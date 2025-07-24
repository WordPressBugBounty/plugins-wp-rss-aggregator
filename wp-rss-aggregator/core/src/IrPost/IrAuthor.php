<?php

declare(strict_types=1);

namespace RebelCode\Aggregator\Core\IrPost;

use function stripos;
use function is_wp_error;
use WP_User;
use RebelCode\Aggregator\Core\Utils\Result;
use RebelCode\Aggregator\Core\Utils\Nullable;
use RebelCode\Aggregator\Core\RssReader\RssNode;
use RebelCode\Aggregator\Core\RssReader\RssAuthor;

use RebelCode\Aggregator\Core\Logger;
use Exception;

class IrAuthor {

	public const DEFAULT_META_KEY = '_wpra_source_author';

	private static ?IrAuthor $default = null;

	public ?int $id;
	public ?string $name;
	public ?string $email;
	public ?string $link;
	public array $meta;

	/**
	 * Constructor.
	 *
	 * @param int|null              $id The ID of the WordPress user, or null if the author needs to be created.
	 * @param string|null           $name The author's name.
	 * @param string|null           $email The author's email.
	 * @param string|null           $link The link to the author's website.
	 * @param array<string,mixed[]> $meta Optional meta data.
	 */
	public function __construct( ?int $id = null, ?string $name = null, ?string $email = null, ?string $link = null, array $meta = array() ) {
		$this->id = $id;
		$this->name = Nullable::normalize( $name );
		$this->email = Nullable::normalize( $email );
		$this->link = Nullable::normalize( $link );
		$this->meta = $meta;
	}

	/**
	 * Finds a matching WordPress user.
	 *
	 * @return WP_User|null The matching user, or null if none was found.
	 */
	public function findMatchingWpUser( $name = '', $email = '' ): ?WP_User {
		$user = null;

		if ( $this->id !== null && $this->id > 0 ) {
			$user = Nullable::normalize( get_user_by( 'id', $this->id ) );
		}

		if ( ! $user && $this->email ) {
			$user = Nullable::normalize( get_user_by( 'email', $email ?? $this->email ) );
		}

		if ( ! $user && ( $username = static::generateUsername( $name ?? $this->name, $email ?? $this->email ) ) ) {
			$user = Nullable::normalize( get_user_by( 'login', $username ) );
		}

		if ( $user === false ) {
			return null;
		} else {
			return $user;
		}
	}

	/**
	 * Gets the ID of the WordPress user that matches the IR author, creating it if necessary.
	 *
	 * @return Result<int> A result containing the ID of the user.
	 */
	public function getOrCreate(): Result {
		// If ID is already set and valid, use it.
		if ( $this->id !== null && $this->id > 0 ) {
			return Result::Ok( $this->id );
		}

		$name = null;
		$email = null;

		if ( $this->name ) {
			$name = $this->name;
			$email = $this->email ?? self::generateEmail( $name );
		} elseif ( $this->email ) {
			$email = $this->email;
			$name = $this->name ?? self::generateName( $email );
		} else {
			return Result::Err( new Exception( 'Cannot create user for author without a name and email address.' ) );
		}

		// Try to find an existing user using email or name/username
		$existingUser = $this->findMatchingWpUser( $name, $email );
		if ( $existingUser instanceof \WP_User ) {
			$this->id = $existingUser->ID; // Update the instance's ID for future reference within this object's lifecycle
			return Result::Ok( $existingUser->ID );
		}

		// If no existing user found, then attempt to create one.
		$createResult = $this->create( $name, $email );
		if ( $createResult->isOk() ) {
			// If creation was successful, update the instance's ID.
			$this->id = $createResult->get();
		}

		return $createResult;
	}

	/**
	 * Creates a WordPress user for the author.
	 *
	 * @return Result<int> A result containing the ID of the created user.
	 */
	public function create( $name, $email ): Result {
		$username = static::generateUsername( $name, $email );
		if ( ! $username ) {
			return Result::Err( new Exception( 'Cannot create author without a username.' ) );
		}

		$id = wp_create_user( $username, wp_generate_password(), $email );

		if ( is_wp_error( $id ) ) {
			return Result::Err( new Exception( $id->get_error_message() ) );
		}

		// If multisite and the current blog is not the main one, add user to current blog.
		if ( is_multisite() ) {
			$current_blog_id = get_current_blog_id();
			// Determine the main site ID. Fallback to 1 if get_network() is not available or doesn't return what we expect.
			$main_site_id = 1;
			if ( function_exists( 'get_network' ) && ( $network = get_network() ) && isset( $network->site_id ) ) {
				$main_site_id = (int) $network->site_id;
			}

			if ( $current_blog_id !== $main_site_id ) {
				$role = get_option( 'default_role', 'subscriber' ); // Fallback to 'subscriber' if option not set
				// Corrected order of arguments for add_user_to_blog: $blog_id, $user_id, $role
				$add_result = add_user_to_blog( $current_blog_id, $id, $role );
				if ( ! $add_result || is_wp_error( $add_result ) ) {
					// Also, it returns true on success, false or WP_Error on failure.
					// More robust error handling might check for WP_Error.
					Logger::warning(
						sprintf(
							'Failed to add user ID %d to blog ID %d with role %s. Error: %s',
							$id,
							$current_blog_id,
							$role,
							is_wp_error( $add_result ) ? $add_result->get_error_message() : 'unknown (returned false)'
						)
					);
					// Not returning Result::Err here as user creation itself succeeded.
					// Depending on requirements, this could be a hard failure.
				}
			}
		}

		// Update user display name and URL if provided
		if ( $name || $this->link ) {
			wp_update_user(
				array(
					'ID' => $id,
					'display_name' => $name ?? '',
					'user_url' => $this->link ?? '',
				)
			);
		}

		foreach ( $this->meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_user_meta( $id, $key, $value );
			}
		}

		return Result::Ok( $id );
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'id' => $this->id,
			'name' => $this->name,
			'email' => $this->email,
			'link' => $this->link,
			'meta' => $this->meta,
		);
	}

	/** @param array<string,mixed> $array The array. */
	public static function fromArray( array $array ): self {
		return new self(
			$array['id'] ?? null,
			$array['name'] ?? null,
			$array['email'] ?? null,
			$array['link'] ?? null,
			$array['meta'] ?? array(),
		);
	}

	/** Creates an IR author from an RSS author. */
	public static function fromRssAuthor( RssAuthor $author ): self {
		return new self( null, $author->getName(), $author->getEmail(), $author->getUri() );
	}

	/** Creates an IR author from an RSS node. */
	public static function fromRssNode( RssNode $node ): self {
		return new self( null, $node->getValue(), $node->getAttr( '', 'email' ), $node->getAttr( '', 'uri' ), );
	}

	/**
	 * Creates an IR author from a WordPress user object.
	 *
	 * @param WP_User $user The user.
	 * @return IrAuthor The IR author.
	 */
	public static function fromWpUser( WP_User $user ): self {
		return new self(
			$user->ID,
			$user->data->display_name,
			$user->data->user_email,
			$user->data->user_url,
			get_user_meta( $user->ID ),
		);
	}

	/**
	 * Creates an IR author from a WordPress user ID.
	 *
	 * @param int $id The ID of the user.
	 * @return IrAuthor|null The created IR author, or null if no WordPress user exists with the given ID.
	 */
	public static function fromWpUserId( int $id ): ?IrAuthor {
		$user = get_user_by( 'id', $id );

		return $user ? static::fromWpUser( $user ) : null;
	}

	/**
	 * Gets the default source author if it exists (marked by a meta entry),
	 * otherwise it is instantiated (but not yet created).
	 */
	public static function getDefault(): self {
		if ( self::$default !== null ) {
			return self::$default;
		}

		$user = null;
		$original_blog_id = null;

		if ( is_multisite() ) {
			$original_blog_id = get_current_blog_id();
			switch_to_blog( 1 );

			$users_on_main_site = get_users(
				array(
					'number' => 1,
					'meta_key' => self::DEFAULT_META_KEY,
					'meta_value' => '1',
				)
			);

			if ( ! is_array( $users_on_main_site ) ) {
				$users_on_main_site = array();
			}

			$user_on_main_site = reset( $users_on_main_site );

			if ( $user_on_main_site instanceof \WP_User ) {
				// Switch back to the original blog to check membership
				switch_to_blog( $original_blog_id );

				if ( is_user_member_of_blog( $user_on_main_site->ID, $original_blog_id ) ) {
					$user = $user_on_main_site;
					Logger::debug( 'Found default source author on main site (ID: ' . $user->ID . ') and user is member of current site (ID: ' . $original_blog_id . ').' );
				} else {
					Logger::debug( 'Default author (ID: ' . $user_on_main_site->ID . ') from main site is not a member of site ' . $original_blog_id . '.' );
					// User found on main site but not a member of the current blog, fall through to site-specific logic.
					// Ensure we switch back to the original blog if we haven't already.
					restore_current_blog(); // Effectively same as switch_to_blog($original_blog_id) if already switched.
				}
			} else {
				Logger::debug( 'No default author found on main site.' );
				// No user found on the main site, switch back and fall through to site-specific logic.
				switch_to_blog( $original_blog_id );
			}
		}

		// If no user from main site is applicable, try to find one on the current site or create a new one.
		if ( ! ( $user instanceof \WP_User ) ) {
			// If not multisite, or if main site user wasn't applicable, look for user on current site.
			// This part of the logic remains similar to original but ensures it runs for the correct blog.
			if ( ! is_multisite() || ( $original_blog_id && get_current_blog_id() == $original_blog_id ) ) {
				// The previous switch_to_blog(1) and then switch_to_blog($original_blog_id)
				// ensures we are on the correct blog context here.
				// Or, if not multisite, we are already in the correct context.
			} else if ( is_multisite() && $original_blog_id ) {
				// This case should ideally not be hit if logic above is correct,
				// but as a safeguard, ensure we are on the original blog.
				switch_to_blog( $original_blog_id );
			}

			$users_on_current_site = get_users(
				array(
					'number' => 1,
					'meta_key' => self::DEFAULT_META_KEY,
					'meta_value' => '1',
				)
			);

			if ( ! is_array( $users_on_current_site ) ) {
				$users_on_current_site = array();
			}

			$user = reset( $users_on_current_site );
		}

		if ( $user instanceof \WP_User ) {
			// If we found a user (either from main site and member of current, or from current site)
			$author = self::fromWpUser( $user );
			Logger::debug( 'Using default source author: #' . $user->ID . ' for site ' . ( $original_blog_id ?? get_current_blog_id() ) );
		} else {
			// If no user found on main or current site, create a new default author for the current site.
			$current_site_id_for_log = $original_blog_id ?? get_current_blog_id();
			Logger::debug( 'No suitable default author found. Creating new default author for site ' . $current_site_id_for_log );
			$author = new self(
				null,
				_x( 'Source Author', 'The name of the default author', 'wprss' ),
				self::generateEmail( 'WPRA Source Author Site ' . $current_site_id_for_log ), // Make email unique per site potentially
				null,
				array(
					self::DEFAULT_META_KEY => '1', // Mark as default for the current site
				)
			);
		}

		// Crucial: If we switched blogs, restore to the original blog context before returning.
		// This handles cases where we might have switched to main site (1) and then to original,
		// or just stayed on original if not multisite.
		// restore_current_blog() handles nested switches correctly.
		if ( $original_blog_id !== null && $original_blog_id !== get_current_blog_id() ) {
			switch_to_blog( $original_blog_id );
		} elseif ( $original_blog_id === null && is_multisite() ) {
			// This case implies we switched to blog 1 but $original_blog_id was not set (which is unlikely with the current logic)
			// or we are in a state where restore_current_blog is needed.
			// However, the more specific switch_to_blog($original_blog_id) above should handle it.
			// Adding restore_current_blog() here as a broader fallback for multisite.
			restore_current_blog();
		}

		self::$default = apply_filters( 'wpra.importer.post.author.default', $author );

		return self::$default;
	}

	/** Clears the statically cached default author instance. */
	public static function clearDefaultAuthorCache(): void {
		self::$default = null;
	}

	/** Checks if a user with a given ID is the default author user. */
	public static function isDefault( int $id ): bool {
		$default = get_user_meta( $id, self::DEFAULT_META_KEY, true );
		return $default === '1';
	}

	/** Generates a name for an author from their email address. */
	public static function generateName( ?string $email ): ?string {
		if ( $email === null ) {
			return null;
		}

		$filtered = filter_var( $email, FILTER_VALIDATE_EMAIL );
		if ( ! $filtered ) {
			return $email;
		}

		$name = strstr( $filtered, '@', true );
		if ( ! $name ) {
			return $email;
		}

		$name = preg_replace( '/[_.]/i', ' ', $name );
		$name = preg_replace( '/\s+/', ' ', $name );

		$nameParts = explode( ' ', $name );
		$capitalized = array_map( 'ucfirst', $nameParts );

		return implode( ' ', $capitalized );
	}

	/** Generates an email address for an author from their name. */
	public static function generateEmail( string $name ): string {
		// Lowercase the author name, remove disallowed chars, and replace spaces with dots
		$username = strtolower( $name );
		$username = preg_replace( '/[<>()\[\]{}.,;:@\"]/', '', $username );
		$username = preg_replace( '/\s+/', '.', strtolower( $username ) );

		$siteUrl = get_site_url();
		$host = parse_url( $siteUrl, PHP_URL_HOST );

		// Fix for domains with no top-level domain name suffix (such as localhost)
		if ( stripos( $host, '.' ) === false ) {
			$host .= '.com';
		}

		return $username . '@' . $host;
	}

	/** Generates a username from a name or email address */
	public static function generateUsername( ?string $name, ?string $email ): ?string {
		if ( $email ) {
			$atSym = stripos( $email, '@' );
			return $atSym ? substr( $email, 0, min( $atSym, 60 ) ) : null;
		} elseif ( $name ) {
			return substr( sanitize_user( $name, true ), 0, 60 );
		} else {
			return null;
		}
	}
}
