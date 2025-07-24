<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\Utils;

abstract class Html {

	/**
	 * The tags that are allowed in post content.
	 *
	 * @var string[]
	 */
	public const WP_ALLOWED_CONTENT_TAGS = array(
		'a',
		'b',
		'i',
		'strong',
		'em',
		'u',
		's',
		'strike',
		'code',
		'pre',
		'br',
		'p',
		'object',
		'embed',
		'iframe',
	);

	protected const TAG_TYPES = array(
		'opening' => 1,
		'closing' => 2,
		'self-closing' => 0,
	);

	/**
	 * Decodes HTML entities in a string using the correct flags and UTF-8 character set.
	 *
	 * @param string $input The input string to decode.
	 * @return string The decoded string.
	 */
	public static function decodeEntities( string $input ): string {
		return html_entity_decode( $input, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Strips HTML tags out of a string, while preserving word boundaries and spaces between them.
	 *
	 * @param string $html The HTML string.
	 * @return string The string stripped of tags.
	 */
	public static function stripTags( string $html ): string {
		// Add a space between any HTML elements before stripping the tags to prevent words from being glued together.
		$html = str_replace( '>', ' >', $html ); // Add spaces between
		$html = strip_tags( $html );
		$html = str_replace( '  ', ' ', trim( $html ) ); // Removed redundant spaces

		return $html;
	}

	/**
	 * Trims the given text by a fixed number of words, and preserving HTML.
	 *
	 * Collapses all white space, trims the text up to a certain number of words, and
	 * preserves all HTML markup. HTML tags do not count as words.
	 * Uses WordPress `wp_trim_words` internally.
	 * Uses mostly trivial regex. Works by removing, then re-adding tags.
	 * Just as well closes open tags by counting them.
	 *
	 * @param string      $text The text to trim.
	 * @param int         $maxWords The maximum number of words.
	 * @param string|null $suffix The suffix to append to the trimmed text. If null, '&hellip;' is used.
	 * @param string[]    $allowedTags The allows tags. Regular array of tag names.
	 * @param array|null  $voidTags The self-closing tags. If null, the list is taken from the HTML5 spec.
	 * @return string The trimmed text.
	 */
	public static function trimHtmlWords(
		string $text,
		int $maxWords,
		?string $suffix = null,
		array $allowedTags = array(),
		?array $voidTags = null
	): string {
		// See http://haacked.com/archive/2004/10/25/usingregularexpressionstomatchhtml.aspx/
		$htmlRegex = <<<EOS
        (</?(\w+)(?:(?:\s+\w+(?:\s*=\s*(?:".*?"|'.*?'|[^'">\s]+))?)+\s*|\s*)/?>)
        EOS;

		$htmlRegexStr = sprintf( '!%1$s!', $htmlRegex );
		// Collapsing single-line white space
		$text = preg_replace( '!\s+!', ' ', $text );

		// Tags that are always self-closing
		$voidTags = array_flip( $voidTags ?? static::voidTags() );

		/*
		 * Split text using tags as delimiters.
		 * The resulting array is a sequence of elements as follows:
		 *  0 - The complete tag that it was delimited by
		 *  1 - The name of that tag
		 *  2 - The text that follows it until the next tag
		 *
		 * Each element contains 2 indexes:
		 *  0 - The element content
		 *  1 - The position in the original string, at which it was found
		 *
		 * For instance:
		 *      <span>hello</span> how do <em>you do</em>?
		 *
		 * Will result in an array (not actual structure) containing:
		 * <span>, span, hello, </span>, span, how do, <em>, em, you do, </em>, em, ?
		 */
		$textArray = preg_split(
			$htmlRegexStr,                 // Match HTML Regex above
			$text,                         // Split the text
			-1,                            // No split limit
			// FLAGS
			PREG_SPLIT_DELIM_CAPTURE       // Capture delimiters (html tags)
				| PREG_SPLIT_OFFSET_CAPTURE    // Record the string offset of each part
		);
		/*
		 * Get first element of the array (leading text with no HTML), and add it to a string.
		 * This string will contain the plain text (no HTML) only after the follow foreach loop.
		 */
		$textStart = array_shift( $textArray );
		$plainText = $textStart[0];

		/*
		 * Chunk the array in groups of 3. This will take each 3 consecutive elements
		 * and group them together.
		 */
		$pieces = array_chunk( $textArray, 3 );

		/*
		 * Iterate over each group and:
		 *  1. Generate plain text without HTML
		 *  2. Add appropriate tag type to each group
		 */
		foreach ( $pieces as $i => $piece ) {
			// Get the data
			$tagPiece = $piece[0];
			$textPiece = $piece[2];
			$tagName = $piece[1][0];
			// Compile all plain text together
			$plainText .= $textPiece[0];
			// Check the tag and assign the proper tag type
			$tag = $tagPiece[0];
			$pieces[ $i ][1][2] =
				( substr( $tag, 0, 2 ) === '</' )
				? self::TAG_TYPES['closing']
				: ( ( substr( $tag, strlen( $tag ) - 2, 2 ) === '/>' || array_key_exists( $tagName, $voidTags ) )
					? self::TAG_TYPES['self-closing']
					: self::TAG_TYPES['opening'] );
		}

		// Stock trimming of words
		$plainText = Strings::trimWords( $plainText, $maxWords, $suffix );

		/*
		 * Put the tags back, using the offsets recorded
		 * This is where the sweet magic happens
		 */

		// Cache to only check `in_array` once for each tag type
		$allowedTagsCache = array();
		// For counting open tags
		$tagsToClose = array();
		// Since some tags will not be included...
		$tagPosOffset = 0;
		$text = $plainText;

		// Iterate the groups once more
		foreach ( $pieces as $piece ) {
			// Tag and tag-name
			$tagPiece = $piece[0];
			$tagNamePiece = $piece[1];
			// Name of the tag
			$tagName = strtolower( $tagNamePiece[0] );
			// Tag type
			$tagType = $tagNamePiece[2];
			// Text of the tag
			$tag = $tagPiece[0];
			// Position of the tag in the original string
			$tagPos = $tagPiece[1];
			$actualTagPos = $tagPos - $tagPosOffset;

			// Caching result
			if ( ! isset( $allowedTagsCache[ $tagName ] ) ) {
				$allowedTagsCache[ $tagName ] = in_array( $tagName, $allowedTags );
			}

			// Whether to stop (tag position is outside the trimmed text)
			if ( $actualTagPos >= strlen( $text ) ) {
				break;
			}

			// Whether to skip tag
			if ( ! $allowedTagsCache[ $tagName ] ) {
				$tagPosOffset += strlen( $tag ); // To correct for removed chars
				continue;
			}

			// If the tag is an opening tag, record it in $tags_to_close
			if ( $tagType === self::TAG_TYPES['opening'] ) {
				$tagsToClose[] = $tagName;
			} // If it is a closing tag, remove it from $tags_to_close
			elseif ( $tagType === self::TAG_TYPES['closing'] ) {
				array_pop( $tagsToClose );
			}

			// Inserting tag back into place
			$text = substr_replace( $text, $tag, $actualTagPos, 0 );
		}

		// Add the appropriate closing tags to all unclosed tags
		foreach ( $tagsToClose as $tagName ) {
			$text .= sprintf( '</%1$s>', $tagName );
		}

		return $text;
	}

	/**
	 * A list of void tags, e.g. tags that don't require a closing tag, also known as self-closing tags.
	 *
	 * @link http://stackoverflow.com/questions/13915201/what-tags-in-html5-are-acknowledged-of-being-self-closing
	 *
	 * @return string[] A list of tag name strings.
	 */
	public static function voidTags(): array {
		return apply_filters(
			'wpra.html.void_tags',
			array(
				'area',
				'base',
				'br',
				'col',
				'command',
				'embed',
				'hr',
				'img',
				'input',
				'keygen',
				'link',
				'meta',
				'param',
				'source',
				'track',
				'wbr',
				'basefont',
				'bgsound',
				'frame',
				'isindex',
			)
		);
	}
}
