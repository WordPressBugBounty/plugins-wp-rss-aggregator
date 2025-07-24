<?php
// The contents of the iframe for the admin UI app
if ( ! defined( 'ABSPATH' ) ) {
	exit( 0 );
}
?>
<!DOCTYPE html>
<html>
	<head>
		<title><?php echo __( 'Aggregator Admin', 'wp-rss-aggregator' ); ?></title>
		<?php echo apply_filters( 'wpra.admin.frame.head', '' ); ?>
	</head>
	<body>
		<?php echo apply_filters( 'wpra.admin.frame.body.start', '' ); ?>
		<div id="wpra-admin-ui"></div>
		<?php echo apply_filters( 'wpra.admin.frame.body.end', '' ); ?>
	</body>
</html>
