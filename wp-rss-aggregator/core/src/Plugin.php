<?php

namespace RebelCode\Aggregator\Core;

use Generator;
use LogicException;

class Plugin {

	public string $version;
	public string $file;
	public string $path;
	public string $url;
	public string $basename;
	public bool $premiumInstalled = false;

	/** Cache for the plugin's current state. */
	private ?string $state = null;
	/** The module currently being run. */
	private ?string $currModId = null;
	/** @var array<string,array{deps:string[],factory:callable}> */
	private array $modules = array();
	/** @var array<string,mixed> A mapping of serviceId => service */
	private array $services = array();

	public function __construct( string $file, string $version ) {
		$this->version = $version;
		$this->file = $file;
		$this->path = rtrim( plugin_dir_path( $file ), '/' );
		$this->url = rtrim( plugin_dir_url( $file ), '/' );
		$this->basename = rtrim( plugin_basename( $file ) );
	}

	/** Loads packages and their modules from a WPRA plugin. */
	public function loadPackages( string $root, iterable $packages ): void {
		foreach ( $packages as $package ) {
			$path = dirname( $root ) . '/' . $package;

			$autoloader = "{$path}/vendor/autoload.php";
			if ( file_exists( $autoloader ) ) {
				require "{$path}/vendor/autoload.php";
			}

			$modules = glob( "$path/modules/*.php" );
			foreach ( $modules as $module ) {
				require $module;
			}
		}
	}

	/** Runs all of the registered plugin modules. */
	public function run(): self {
		foreach ( $this->modules as $id => $_ ) {
			$this->runModule( $id );
		}
		return $this;
	}

	/** @return mixed */
	public function get( string $id ) {
		return $this->resolveId( $id, __METHOD__ . '()' );
	}

	/**
	 * Adds a module to the plugin.
	 *
	 * @param string           $id The ID of the module.
	 * @param list<string>     $deps The IDs of the modules to depend on.
	 * @param callable():mixed $factory A function that receives the Plugin
	 *        and the value of the modules given in $deps as arguments, and
	 *        returns the module's value.
	 */
	public function addModule( string $id, array $deps, callable $factory ): self {
		if ( $this->currModId !== null ) {
			throw new LogicException( "Cannot add a module (\"$id\") from inside another module (\"$this->currModId\")" );
		}

		$this->modules[ $id ] = array(
			'deps' => $deps,
			'factory' => $factory,
		);
		return $this;
	}

	/** @return mixed */
	private function runModule( string $mid ) {
		if ( array_key_exists( $mid, $this->services ) ) {
			return;
		}

		if ( $this->currModId !== null ) {
			throw new LogicException( "Cannot run module \"$mid\" while running module \"{$this->currModId}\"" );
		}

		$module = $this->modules[ $mid ] ?? null;
		if ( $module === null ) {
			throw new LogicException( "Unknown module \"$mid\"" );
		}

		$args = array();
		foreach ( $module['deps'] as $sid ) {
			$args[] = $this->resolveId( $sid, $mid );
		}

		$this->currModId = $mid;

		$result = call_user_func_array( $module['factory'], $args );

		if ( $result instanceof Generator ) {
			throw new LogicException( 'Cannot use generator as module service' );
		}

		$this->services[ $mid ] = $result;
		$this->currModId = null;

		return $result;
	}

	/**
	 * Resolves a service ID. This will also run the module that provides the
	 * service, if it hasn't already been run.
	 *
	 * @return mixed
	 */
	private function resolveId( string $sid, string $requester ) {
		if ( array_key_exists( $sid, $this->services ) ) {
			return $this->services[ $sid ];
		}

		$mid = $this->getModuleForService( $sid );
		if ( $mid === null ) {
			throw new LogicException( "Cannot resolve module for \"$sid\", requested by \"$requester\"" );
		}

		$modHasRun = array_key_exists( $mid, $this->services );
		if ( $modHasRun ) {
			throw new LogicException( "Module \"$mid\" does not provide \"$sid\", requested by \"$requester\"" );
		}

		return $this->runModule( $mid );
	}

	private function getModuleForService( string $sid ): ?string {
		$pieces = explode( '.', $sid );

		while ( count( $pieces ) > 0 ) {
			$mid = implode( '.', $pieces );

			if ( array_key_exists( $mid, $this->modules ) ) {
				return $mid;
			}

			array_pop( $pieces );
		}

		return null;
	}

	/** @return array<string,list<string>> */
	public function getModuleGraph(): array {
		$graph = array();
		foreach ( $this->modules as $mid => ['deps' => $deps] ) {
			$graph[ $mid ] = array();

			foreach ( $deps as $sid ) {
				$depModId = $this->getModuleForService( $sid );
				if ( $depModId !== null ) {
					$graph[ $mid ][] = $depModId;
				}
			}
			$graph[ $mid ] = array_unique( $graph[ $mid ] );
		}
		return $graph;
	}

	public function getState(): string {
		if ( $this->state === null ) {
			if ( get_option( 'wpra_version', false ) !== false ) {
				$this->state = State::Normal;
			} elseif ( $this->hasV4Data() ) {
				$this->state = State::V4Migration;
			} else {
				$this->state = State::Onboarding;
			}
		}
		return $this->state;
	}

	public function hasV4Data(): bool {
		// look for options from v4
		$v4Settings = get_option( 'wprss_settings_general', null );
		$v4Notices = get_option( 'wprss_admin_notices', null );
		$v4Licenses = get_option( 'wprss_settings_license_keys', null );

		$hasV4Options = ! empty( $v4Settings ) && ! empty( $v4Notices ) && ! empty( $v4Licenses );
		if ( $hasV4Options ) {
			return true;
		}

		/** @var \wpdb $wpdb */
		global $wpdb;
		$wpdb->get_results(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wprss_feed' LIMIT 1"
		);

		if ( $wpdb->last_error ) {
			return false;
		} else {
			return $wpdb->num_rows > 0;
		}
	}
}
