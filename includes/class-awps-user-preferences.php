<?php
/**
 * Per-user palette preferences: activation shortcut, theme, favorites,
 * recent/frequent command usage.
 *
 * The activation shortcut is a personal, per-user setting stored in user
 * meta — not a site-wide option.
 *
 * @package advanced-wp-spotlight
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AWPS_User_Preferences {

	const META_SHORTCUT  = 'awps_shortcut';
	const META_FAVORITES = 'awps_favorites';
	const META_RECENTS   = 'awps_recents';
	const META_HINT_SEEN = 'awps_hint_seen';
	const META_THEME     = 'awps_theme';

	const DEFAULT_SHORTCUT = 'ctrl+/';
	const DEFAULT_THEME    = 'dark';
	const MAX_RECENTS      = 30;

	/**
	 * Shortcuts likely to collide with the OS/browser. The UI warns about
	 * these but does not block them (spec: warn, don't block).
	 *
	 * @return string[]
	 */
	public static function risky_shortcuts() {
		return array(
			'ctrl+space', // IME switching.
			'ctrl+t',
			'ctrl+w',
			'ctrl+n',
			'ctrl+l',
			'ctrl+d',
			'ctrl+f',
			'ctrl+p',
			'ctrl+r',
			'ctrl+s',
			'ctrl+a',
			'ctrl+c',
			'ctrl+v',
			'ctrl+x',
			'ctrl+z',
			'meta+space', // macOS Spotlight itself.
			'meta+q',
			'meta+w',
			'meta+t',
			'alt+space',
			'f5',
		);
	}

	/**
	 * Current user's activation shortcut.
	 *
	 * @param int $user_id Optional user id; defaults to current user.
	 * @return string e.g. "ctrl+/", "ctrl+shift+p".
	 */
	public static function get_shortcut( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$value   = get_user_meta( $user_id, self::META_SHORTCUT, true );
		return $value ? $value : self::DEFAULT_SHORTCUT;
	}

	/**
	 * Save the activation shortcut after validation.
	 *
	 * @param string $shortcut Shortcut string, e.g. "ctrl+shift+k".
	 * @param int    $user_id  Optional user id.
	 * @return true|WP_Error
	 */
	public static function set_shortcut( $shortcut, $user_id = 0 ) {
		$user_id  = $user_id ? $user_id : get_current_user_id();
		$shortcut = strtolower( trim( (string) $shortcut ) );

		if ( ! preg_match( '/^((ctrl|alt|shift|meta)\+){0,3}[a-z0-9\/\.\,\;\'\[\]\-\=\`]$|^((ctrl|alt|shift|meta)\+){0,3}(space|enter|f[1-9]|f1[0-2])$/', $shortcut ) ) {
			return new WP_Error( 'awps_bad_shortcut', __( 'That shortcut format is not valid.', 'advanced-wp-spotlight' ) );
		}

		// Require at least one modifier unless it's a function key.
		if ( false === strpos( $shortcut, '+' ) && ! preg_match( '/^f[0-9]+$/', $shortcut ) ) {
			return new WP_Error( 'awps_bad_shortcut', __( 'Please include a modifier key (Ctrl, Alt, Shift or Cmd).', 'advanced-wp-spotlight' ) );
		}

		update_user_meta( $user_id, self::META_SHORTCUT, $shortcut );
		return true;
	}

	/**
	 * Palette theme for the current user: dark | light | auto.
	 * "auto" follows the OS color scheme; dark is the default.
	 *
	 * @return string
	 */
	public static function get_theme() {
		$value = get_user_meta( get_current_user_id(), self::META_THEME, true );
		return in_array( $value, array( 'dark', 'light', 'auto' ), true ) ? $value : self::DEFAULT_THEME;
	}

	/**
	 * Save the palette theme.
	 *
	 * @param string $theme dark|light|auto.
	 * @return true|WP_Error
	 */
	public static function set_theme( $theme ) {
		if ( ! in_array( $theme, array( 'dark', 'light', 'auto' ), true ) ) {
			return new WP_Error( 'awps_bad_theme', __( 'Unknown palette theme.', 'advanced-wp-spotlight' ) );
		}
		update_user_meta( get_current_user_id(), self::META_THEME, $theme );
		return true;
	}

	/**
	 * Favorite (pinned) command ids for the current user.
	 *
	 * @return string[]
	 */
	public static function get_favorites() {
		$favorites = get_user_meta( get_current_user_id(), self::META_FAVORITES, true );
		return is_array( $favorites ) ? array_values( $favorites ) : array();
	}

	/**
	 * Toggle a favorite command.
	 *
	 * @param string $command_id Command id.
	 * @return string[] Updated favorites.
	 */
	public static function toggle_favorite( $command_id ) {
		$command_id = substr( sanitize_text_field( $command_id ), 0, 191 );
		if ( '' === $command_id ) {
			return self::get_favorites();
		}
		$favorites = self::get_favorites();
		$position   = array_search( $command_id, $favorites, true );

		if ( false !== $position ) {
			unset( $favorites[ $position ] );
		} else {
			$favorites[] = $command_id;
		}

		$favorites = array_values( array_slice( $favorites, 0, 50 ) );
		update_user_meta( get_current_user_id(), self::META_FAVORITES, $favorites );

		return $favorites;
	}

	/**
	 * Recent/frequent command usage: array of id => { count, last }.
	 *
	 * @return array
	 */
	public static function get_recents() {
		$recents = get_user_meta( get_current_user_id(), self::META_RECENTS, true );
		return is_array( $recents ) ? $recents : array();
	}

	/**
	 * Record one command execution (for recents + frequency ranking).
	 *
	 * @param string $command_id Command id.
	 * @return array Updated recents map.
	 */
	public static function record_usage( $command_id ) {
		$command_id = substr( sanitize_text_field( $command_id ), 0, 191 );
		if ( '' === $command_id ) {
			return self::get_recents();
		}
		$recents = self::get_recents();

		if ( isset( $recents[ $command_id ] ) ) {
			$recents[ $command_id ]['count']++;
			$recents[ $command_id ]['last'] = time();
		} else {
			$recents[ $command_id ] = array(
				'count' => 1,
				'last'  => time(),
			);
		}

		// Keep the map bounded: drop the least-recently-used overflow.
		if ( count( $recents ) > self::MAX_RECENTS ) {
			uasort(
				$recents,
				function ( $a, $b ) {
					return $b['last'] <=> $a['last'];
				}
			);
			$recents = array_slice( $recents, 0, self::MAX_RECENTS, true );
		}

		update_user_meta( get_current_user_id(), self::META_RECENTS, $recents );
		return $recents;
	}

	/**
	 * Whether the one-time discoverability hint has been dismissed.
	 *
	 * @return bool
	 */
	public static function hint_seen() {
		return (bool) get_user_meta( get_current_user_id(), self::META_HINT_SEEN, true );
	}

	/**
	 * Mark the discoverability hint as dismissed.
	 */
	public static function dismiss_hint() {
		update_user_meta( get_current_user_id(), self::META_HINT_SEEN, 1 );
	}
}
