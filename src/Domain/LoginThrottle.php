<?php

namespace BeltoftCommerce\Domain;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A limit on how fast logins - trade and retail - can be guessed at.
 *
 * beltoft-hardening's own notes name "Rate limiting (nginx `limit_req
 * zone=wp_login`)" as the defence against guessing. That zone is declared in
 * nginx.conf and never applied: there is no `limit_req` directive anywhere in
 * the configuration, so nothing has ever been limited. The trade login is a
 * front-end form on a public host, it authenticates real customer accounts, and
 * until nginx is fixed it can be tried at the speed of the network.
 *
 * This does not replace the nginx rule - a request that never reaches PHP is
 * always cheaper to refuse - but it does not depend on someone editing a shared
 * web server either, and it survives that file being replaced.
 *
 * FAIL2BAN COVERS NONE OF THIS, which was worth checking rather than assuming.
 * It is installed and running, and its `wordpress` jail looks like exactly the
 * right thing - until you read it. Debian's jail.d/defaults-debian.conf sets
 * `backend = systemd` for every jail, which makes the `logpath` in jail.local
 * dead configuration: the jail watches the journal, its filter matches nginx
 * access-log lines, and nginx logs to a file. It has read nothing, ever -
 * `Total failed: 0` since the day it was installed. Its sibling
 * `nginx-limit-req` is inert twice over, for the same backend reason and for
 * the missing `limit_req` above.
 *
 * Even repaired, that jail would not see a trade login: this one is a Bricks
 * form posting to admin-ajax.php, and a filter broad enough to match
 * admin-ajax would match every cart update on the site.
 *
 * So the two halves are split by what each is good at. This class decides,
 * because only the application knows that a given POST was a login, whose
 * account it named, and that the same account is being tried from six
 * addresses. fail2ban enforces, because a ban at the firewall costs nothing to
 * serve. The handover is one syslog line per refusal - see report() below, and
 * filter.d/beltoft-b2b-login.conf for the other end of it.
 *
 * WHAT IT COUNTS. Failures per IP, and failures per account, separately. Per IP
 * catches one machine working through passwords. Per account catches a spread
 * attempt from many addresses against one known customer, which the IP counter
 * alone would never see.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It never says which of the two limits was
 * hit, and it never distinguishes a locked account from a wrong password: both
 * would confirm to a stranger that an address is a real customer here. It also
 * lets a correct password through as normal once the window has passed, rather
 * than holding a lockout open - the aim is to make guessing slow, not to give
 * anyone a way to lock a competitor's account out of their own shop.
 */
class LoginThrottle {

	/** How long failures are remembered. */
	private const WINDOW = 900;

	/** Failures from one address before that address has to wait. */
	private const MAX_PER_IP = 12;

	/** Failures against one account before that account has to wait. */
	private const MAX_PER_ACCOUNT = 8;

	private const IP_PREFIX      = 'beltoft_lt_ip_';
	private const ACCOUNT_PREFIX = 'beltoft_lt_ac_';

	/**
	 * The syslog identity fail2ban's filter matches on.
	 *
	 * Changing this string means changing filter.d/beltoft-b2b-login.conf with
	 * it - `_daemon` there is this value.
	 */
	private const LOG_IDENT = 'beltoft-b2b-login';

	/** One line per request, however many times the limit is consulted. */
	private static bool $reported = false;

	public static function register(): void {
		// Priority 30: after WordPress's own checks and after beltoft-hardening,
		// so this only ever sees attempts that would otherwise have succeeded
		// or failed on the password itself.
		add_filter( 'authenticate', array( __CLASS__, 'maybe_block' ), 30, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failure' ) );
		add_action( 'wp_login', array( __CLASS__, 'clear' ), 10, 2 );
		/*
		 * Bricks sends a failed form back through wp_send_json_error(), which
		 * returns before its own bricks/form/response filter, so a message
		 * cannot be corrected after the fact. bricks/form/validate runs before
		 * the login action instead - early enough to refuse the attempt and say
		 * why, and early enough that wp_signon() is never reached at all, which
		 * is the cheaper refusal anyway.
		 */
		add_filter( 'bricks/form/validate', array( __CLASS__, 'validate' ), 10, 2 );
	}

	/**
	 * @param null|WP_User|WP_Error $user
	 * @param string                $username
	 * @param string                $password
	 * @return null|WP_User|WP_Error
	 */
	public static function maybe_block( $user, $username = '', $password = '' ) {
		if ( ! self::applies() || '' === (string) $password ) {
			return $user;
		}

		if ( self::over_limit( (string) $username ) ) {
			return new WP_Error(
				'beltoft_login_throttled',
				self::message()
			);
		}

		return $user;
	}

	public static function message(): string {
		return __( 'Too many attempts. Please wait a few minutes before trying again.', 'beltoft-commerce' );
	}

	/**
	 * Refuse an over-limit login before Bricks runs the login action.
	 *
	 * @param array $errors Validation errors so far.
	 * @param mixed $form   Bricks form instance.
	 * @return array
	 */
	public static function validate( $errors, $form = null ) {
		if ( ! self::applies() || ! is_array( $errors ) || ! is_object( $form ) ) {
			return $errors;
		}

		if ( ! method_exists( $form, 'get_settings' ) || ! method_exists( $form, 'get_fields' ) ) {
			return $errors;
		}

		$settings = (array) $form->get_settings();

		// Only a form that actually signs somebody in.
		if ( ! in_array( 'login', (array) ( $settings['actions'] ?? array() ), true ) ) {
			return $errors;
		}

		$fields   = (array) $form->get_fields();
		$key      = isset( $settings['loginName'] ) ? 'form-field-' . $settings['loginName'] : '';
		$username = $key && isset( $fields[ $key ] ) ? (string) $fields[ $key ] : '';

		if ( self::over_limit( $username ) ) {
			$errors[] = self::message();
		}

		return $errors;
	}

	public static function record_failure( $username ): void {
		if ( ! self::applies() ) {
			return;
		}

		self::bump( self::IP_PREFIX . self::ip_key() );

		$account = self::account_key( (string) $username );

		if ( '' !== $account ) {
			self::bump( self::ACCOUNT_PREFIX . $account );
		}
	}

	/**
	 * A successful sign-in clears that account - not the address it came from.
	 *
	 * Otherwise a partner who mistyped a password five times and then got it
	 * right would still be carrying those five failures for a quarter of an
	 * hour, and the next slip would lock them out of their own account.
	 *
	 * The address's counter is left alone. Clearing it too let anyone zero it
	 * by signing in to an account of their own - a free retail one will do -
	 * between rounds of guessing at others, which made the per-IP limit stop
	 * nothing. A person's own few misses stay well under it.
	 *
	 * @param string  $login
	 * @param WP_User $user
	 */
	public static function clear( $login, $user = null ): void {
		if ( ! self::applies() ) {
			return;
		}

		$account = self::account_key( (string) $login );

		if ( '' !== $account ) {
			delete_transient( self::ACCOUNT_PREFIX . $account );
		}
	}

	private static function over_limit( string $username ): bool {
		$limit = self::limit_hit( $username );

		if ( '' === $limit ) {
			return false;
		}

		self::report( $limit );

		return true;
	}

	/**
	 * Which limit is standing in the way, if either.
	 *
	 * @return string 'ip', 'account', or '' for neither.
	 */
	private static function limit_hit( string $username ): string {
		if ( self::count( self::IP_PREFIX . self::ip_key() ) >= self::MAX_PER_IP ) {
			return 'ip';
		}

		$account = self::account_key( $username );

		return '' !== $account
			&& self::count( self::ACCOUNT_PREFIX . $account ) >= self::MAX_PER_ACCOUNT
				? 'account'
				: '';
	}

	/**
	 * Hand a refused attempt to the system log, for fail2ban to act on.
	 *
	 * WHY ONLY REFUSALS. A wrong password is ordinary - partners mistype, and
	 * the counters above exist precisely so that a handful of those costs
	 * nobody anything. A line is written only once an attempt has been refused,
	 * which means the client was already told to wait and tried anyway. Ten of
	 * those is not a person who cannot remember their password.
	 *
	 * That distinction is what makes a firewall ban safe to automate here. A
	 * ban applies to the whole server, retail included, so it must not be
	 * reachable by a customer having a bad morning: they hit this limit, get a
	 * readable message, and are never reported.
	 *
	 * WHY SYSLOG AND NOT A FILE OF OUR OWN. A file would need creating,
	 * permissions, and a logrotate rule that fail2ban's own file handle
	 * survives - three things to get wrong outside this repository. syslog(3)
	 * is already wired on this host: LOG_AUTHPRIV lands in /var/log/auth.log
	 * and in the journal, both rotated already, and it is the same route
	 * sshd's own failures take.
	 *
	 * WHY THE ACCOUNT IS NOT NAMED. fail2ban only needs the address. Trade
	 * logins are email addresses, and writing a customer's into a log that is
	 * rotated, archived and possibly shipped elsewhere buys an operator
	 * nothing they cannot get from the admin screens.
	 */
	private static function report( string $limit ): void {
		if ( self::$reported || ! function_exists( 'openlog' ) ) {
			return;
		}

		// Set before the filter, so opting out still costs only one check.
		self::$reported = true;

		/**
		 * Filters whether a refused trade login is written to the system log.
		 *
		 * @param bool   $report Whether to write the line.
		 * @param string $limit  'ip' or 'account'.
		 */
		if ( ! apply_filters( 'beltoft_commerce_login_syslog', true, $limit ) ) {
			return;
		}

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		/*
		 * Validated, not merely non-empty. This string is about to become a
		 * line in an authentication log that a firewall reads addresses out
		 * of; anything that is not an address has no business being in it.
		 */
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return;
		}

		openlog( self::LOG_IDENT, LOG_PID | LOG_ODELAY, LOG_AUTHPRIV );
		syslog( LOG_WARNING, sprintf( 'Authentication blocked from %s (%s limit)', $ip, $limit ) );

		/*
		 * openlog() sets the identity for the whole process and these workers
		 * are reused by every other request on the host, so it is put back.
		 */
		closelog();
	}

	/**
	 * Count a failure without moving the end of the window.
	 *
	 * The window is deliberately not extended on each failure. A counter that
	 * restarts on every attempt can be held open indefinitely, which turns a
	 * guessing limit into a way of keeping somebody locked out. set_transient()
	 * always restarts the expiry it is given, so the window's end is stored in
	 * the value itself and each write only gets what is left of it - writing a
	 * fresh WINDOW every time is exactly the restart this rules out.
	 */
	private static function bump( string $key ): void {
		$stored = get_transient( $key );
		$now    = time();

		$until = is_array( $stored ) && (int) ( $stored['until'] ?? 0 ) > $now
			? (int) $stored['until']
			: $now + self::WINDOW;

		set_transient( $key, array(
			'count' => self::count( $key ) + 1,
			'until' => $until,
		), max( 1, $until - $now ) );
	}

	/** Failures recorded in the window still running, 0 once it has ended. */
	private static function count( string $key ): int {
		$stored = get_transient( $key );

		if ( is_array( $stored ) ) {
			return (int) ( $stored['until'] ?? 0 ) > time() ? (int) ( $stored['count'] ?? 0 ) : 0;
		}

		// A counter written before the window was stored with it.
		return (int) $stored;
	}

	/**
	 * Hashed, and never the address itself.
	 *
	 * These rows sit in the options table, which is read by plenty of code that
	 * has no business knowing who tried to sign in from where, and they outlive
	 * the request. A hash counts just as well.
	 */
	private static function ip_key(): string {
		/*
		 * REMOTE_ADDR, and nothing else.
		 *
		 * This used to prefer CF-Connecting-IP and X-Real-IP, which was worse
		 * than useless: nginx overwrites X-Real-IP from the trusted proxy, but
		 * CF-Connecting-IP is passed through exactly as sent and there is no
		 * Cloudflare in front of this site. Anyone could send a different value
		 * on every request and get a fresh counter each time - a rate limit
		 * that politely asked to be bypassed.
		 *
		 * nginx's real_ip module already rewrites REMOTE_ADDR from X-Real-IP
		 * when the request genuinely comes from the proxy at
		 * set_real_ip_from, so this is both the true client address and the one
		 * value a client cannot choose. If a real CDN is ever put in front,
		 * teach nginx about it there rather than trusting a header here.
		 */
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		return substr( md5( 'blt-lt|' . $ip ), 0, 20 );
	}

	private static function account_key( string $username ): string {
		$username = trim( strtolower( $username ) );

		return '' === $username ? '' : substr( md5( 'blt-lt|' . $username ), 0, 20 );
	}

	/**
	 * Both hosts.
	 *
	 * It used to be the trade host only, on the reasoning that retail's login
	 * was somebody else's to limit. Nobody limits it, and partner accounts are
	 * ordinary accounts of this same install - so a partner's password could
	 * be guessed through the retail login instead, without limit. The counters
	 * are shared, so guesses split between the two hosts still add up.
	 */
	private static function applies(): bool {
		return ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}
}
