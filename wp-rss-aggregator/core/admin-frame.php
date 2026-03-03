<?php
// The contents of the iframe for the admin UI app
if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}
?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo esc_html__( 'Aggregator Admin', 'wp-rss-aggregator' ); ?></title>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hook allows trusted markup injection in admin frame head. ?>
		<?php echo apply_filters( 'wpra.admin.frame.head', '' ); ?>
	</head>
	<body>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hook allows trusted markup injection in admin frame body. ?>
		<?php echo apply_filters( 'wpra.admin.frame.body.start', '' ); ?>
		<div id="wpra-admin-ui"></div>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hook allows trusted markup injection in admin frame body. ?>
		<?php echo apply_filters( 'wpra.admin.frame.body.end', '' ); ?>
	</body>
</html>
