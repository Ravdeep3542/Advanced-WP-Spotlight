<?php
/**
 * Command registry.
 *
 * Every command declares the WordPress capability required to see and run it.
 * Capability checks happen at three points (see the master spec §4.1.1):
 *   1. Index build time  — get_for_current_user() strips commands the user can't run.
 *   2. Render time       — the JS layer re-checks nothing is stale (defensive).
 *   3. Execution time    — the REST controller re-verifies current_user_can()
 *                          before dispatching. That check is non-negotiable.
 *
 * Command schema:
 *   id          (string, required)  Unique id. Third parties should namespace as "pluginSlug:command-id".
 *   title       (string, required)  Human label shown in the palette.
 *   category    (string)            Group label, e.g. "Navigation", "Actions".
 *   keywords    (string[])          Extra fuzzy-match aliases.
 *   capability  (string)            WP capability required. Defaults to "read".
 *   type        (string)            Handler type: "url" | "rest" | "copy" | "js".
 *   url         (string)            For type=url: destination (admin) URL.
 *   action      (string)            For type=rest: server action id executed via REST.
 *   payload     (mixed)             For type=copy: text to copy. For type=js: event detail.
 *   args        (array)             Optional extra args sent to the REST action.
 *   icon        (string)            Dashicon slug (without the "dashicons-" prefix).
 *   context     (string[])          Screen ids/prefixes where this command should be boosted.
 *   destructive (bool)              Phase 2 stub. Destructive commands are refused in Phase 1.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_Command_Registry {

	/**
	 * Registered commands, keyed by id.
	 *
	 * @var array[]
	 */
	private $commands = array();

	/**
	 * REST action callbacks, keyed by action id.
	 *
	 * @var callable[]
	 */
	private $actions = array();

	/**
	 * Whether built-in + third-party registration has run.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Register a command.
	 *
	 * @param array $command Command definition (see schema above).
	 * @return bool True when accepted.
	 */
	public function register( array $command ) {
		if ( empty( $command['id'] ) || empty( $command['title'] ) ) {
			return false;
		}

		$defaults = array(
			'category'    => __( 'Commands', 'advanced-wp-spotlight' ),
			'keywords'    => array(),
			'capability'  => 'read',
			'type'        => 'url',
			'url'         => '',
			'action'      => '',
			'payload'     => '',
			'args'        => array(),
			'icon'        => 'marker',
			'context'     => array(),
			'destructive' => false,
			'hidden'      => false, // Hidden commands are executable (inline actions) but never listed.
			'confirm'     => '',    // Confirmation message shown before a destructive command runs.
			'confirm_input' => '',  // Text the user must type to confirm (irreversible tier). Empty = button confirm.
		);

		$command                       = wp_parse_args( $command, $defaults );
		$command['keywords']           = array_map( 'strval', (array) $command['keywords'] );
		$command['destructive']        = (bool) $command['destructive'];
		$this->commands[ $command['id'] ] = $command;

		return true;
	}

	/**
	 * Register a server-side action handler used by type=rest commands.
	 *
	 * @param string   $action_id Action id.
	 * @param callable $callback  function( array $args ): array|WP_Error — should return
	 *                            array( 'message' => string ) on success.
	 */
	public function register_action( $action_id, $callback ) {
		$this->actions[ $action_id ] = $callback;
	}

	/**
	 * Get a REST action callback.
	 *
	 * @param string $action_id Action id.
	 * @return callable|null
	 */
	public function get_action( $action_id ) {
		return isset( $this->actions[ $action_id ] ) ? $this->actions[ $action_id ] : null;
	}

	/**
	 * Get a single command by id (after loading).
	 *
	 * @param string $id Command id.
	 * @return array|null
	 */
	public function get( $id ) {
		$this->load();
		return isset( $this->commands[ $id ] ) ? $this->commands[ $id ] : null;
	}

	/**
	 * All registered commands, unfiltered. Internal / testing use.
	 *
	 * @return array[]
	 */
	public function get_all() {
		$this->load();
		return $this->commands;
	}

	/**
	 * Commands the current user is allowed to see and run.
	 *
	 * This is the index-build-time permission gate: anything the user lacks
	 * capability for is never sent to the browser at all. Destructive commands
	 * are also excluded entirely in Phase 1.
	 *
	 * @return array[] Numerically indexed list, safe to JSON-encode.
	 */
	public function get_for_current_user() {
		$this->load();

		$visible = array();
		foreach ( $this->commands as $command ) {
			if ( ! current_user_can( $command['capability'] ) ) {
				continue;
			}
			$visible[] = $command;
		}

		return array_values( $visible );
	}

	/**
	 * Run built-in and third-party registration exactly once.
	 */
	private function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		/**
		 * Register commands with the palette.
		 *
		 * Third-party plugins should use this action to call
		 * $registry->register() / $registry->register_action().
		 * Namespace command ids as "pluginSlug:command-id".
		 *
		 * @param AWPS_Command_Registry $registry The registry instance.
		 */
		do_action( 'awps_register_commands', $this );

		/**
		 * Filter the full raw command list before capability filtering.
		 *
		 * @param array[] $commands Commands keyed by id.
		 */
		$this->commands = apply_filters( 'awps_commands', $this->commands );
	}
}
