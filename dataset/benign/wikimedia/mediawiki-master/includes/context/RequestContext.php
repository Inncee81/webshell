<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @since 1.18
 *
 * @author Alexandre Emsenhuber
 * @author Daniel Friesen
 * @file
 */

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;
use Wikimedia\IPUtils;
use Wikimedia\ScopedCallback;

/**
 * Group all the pieces relevant to the context of a request into one instance
 */
class RequestContext implements IContextSource, MutableContext {
	/**
	 * @var WebRequest
	 */
	private $request;

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var WikiPage
	 */
	private $wikipage;

	/**
	 * @var OutputPage
	 */
	private $output;

	/**
	 * @var User
	 */
	private $user;

	/**
	 * @var Language
	 */
	private $lang;

	/**
	 * @var Skin
	 */
	private $skin;

	/**
	 * @var Timing
	 */
	private $timing;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var RequestContext
	 */
	private static $instance = null;

	/**
	 * Boolean flag to guard against recursion in getLanguage
	 * @var bool
	 */
	private $languageRecursion = false;

	/**
	 * @param Config $config
	 */
	public function setConfig( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @return Config
	 */
	public function getConfig() {
		if ( $this->config === null ) {
			// @todo In the future, we could move this to WebStart.php so
			// the Config object is ready for when initialization happens
			$this->config = MediaWikiServices::getInstance()->getMainConfig();
		}

		return $this->config;
	}

	/**
	 * @param WebRequest $request
	 */
	public function setRequest( WebRequest $request ) {
		$this->request = $request;
	}

	/**
	 * @return WebRequest
	 */
	public function getRequest() {
		if ( $this->request === null ) {
			global $wgCommandLineMode;
			// create the WebRequest object on the fly
			if ( $wgCommandLineMode ) {
				$this->request = new FauxRequest( [] );
			} else {
				$this->request = new WebRequest();
			}
		}

		return $this->request;
	}

	/**
	 * @deprecated since 1.27 use a StatsdDataFactory from MediaWikiServices (preferably injected)
	 *
	 * @return IBufferingStatsdDataFactory
	 */
	public function getStats() {
		return MediaWikiServices::getInstance()->getStatsdDataFactory();
	}

	/**
	 * @return Timing
	 */
	public function getTiming() {
		if ( $this->timing === null ) {
			$this->timing = new Timing( [
				'logger' => LoggerFactory::getInstance( 'Timing' )
			] );
		}
		return $this->timing;
	}

	/**
	 * @param Title|null $title
	 */
	public function setTitle( Title $title = null ) {
		$this->title = $title;
		// Erase the WikiPage so a new one with the new title gets created.
		$this->wikipage = null;
	}

	/**
	 * @return Title|null
	 */
	public function getTitle() {
		if ( $this->title === null ) {
			global $wgTitle; # fallback to $wg till we can improve this
			$this->title = $wgTitle;
			$logger = LoggerFactory::getInstance( 'GlobalTitleFail' );
			$logger->info(
				__METHOD__ . ' called with no title set.',
				[ 'exception' => new Exception ]
			);
		}

		return $this->title;
	}

	/**
	 * Check, if a Title object is set
	 *
	 * @since 1.25
	 * @return bool
	 */
	public function hasTitle() {
		return $this->title !== null;
	}

	/**
	 * Check whether a WikiPage object can be get with getWikiPage().
	 * Callers should expect that an exception is thrown from getWikiPage()
	 * if this method returns false.
	 *
	 * @since 1.19
	 * @return bool
	 */
	public function canUseWikiPage() {
		if ( $this->wikipage ) {
			// If there's a WikiPage object set, we can for sure get it
			return true;
		}
		// Only pages with legitimate titles can have WikiPages.
		// That usually means pages in non-virtual namespaces.
		$title = $this->getTitle();
		return $title ? $title->canExist() : false;
	}

	/**
	 * @since 1.19
	 * @param WikiPage $wikiPage
	 */
	public function setWikiPage( WikiPage $wikiPage ) {
		$pageTitle = $wikiPage->getTitle();
		if ( !$this->hasTitle() || !$pageTitle->equals( $this->getTitle() ) ) {
			$this->setTitle( $pageTitle );
		}
		// Defer this to the end since setTitle sets it to null.
		$this->wikipage = $wikiPage;
	}

	/**
	 * Get the WikiPage object.
	 * May throw an exception if there's no Title object set or the Title object
	 * belongs to a special namespace that doesn't have WikiPage, so use first
	 * canUseWikiPage() to check whether this method can be called safely.
	 *
	 * @since 1.19
	 * @throws MWException
	 * @return WikiPage
	 */
	public function getWikiPage() {
		if ( $this->wikipage === null ) {
			$title = $this->getTitle();
			if ( $title === null ) {
				throw new MWException( __METHOD__ . ' called without Title object set' );
			}
			$this->wikipage = WikiPage::factory( $title );
		}

		return $this->wikipage;
	}

	/**
	 * @param OutputPage $output
	 */
	public function setOutput( OutputPage $output ) {
		$this->output = $output;
	}

	/**
	 * @return OutputPage
	 */
	public function getOutput() {
		if ( $this->output === null ) {
			$this->output = new OutputPage( $this );
		}

		return $this->output;
	}

	/**
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
		// Invalidate cached user interface language
		$this->lang = null;
	}

	/**
	 * @return User
	 */
	public function getUser() {
		if ( $this->user === null ) {
			$this->user = User::newFromSession( $this->getRequest() );
		}

		return $this->user;
	}

	/**
	 * Accepts a language code and ensures it's sane. Outputs a cleaned up language
	 * code and replaces with $wgLanguageCode if not sane.
	 * @param string $code Language code
	 * @return string
	 */
	public static function sanitizeLangCode( $code ) {
		global $wgLanguageCode;

		// BCP 47 - letter case MUST NOT carry meaning
		$code = strtolower( $code );

		# Validate $code
		if ( !$code
			|| !MediaWikiServices::getInstance()->getLanguageNameUtils()
				->isValidCode( $code )
			|| $code === 'qqq'
		) {
			$code = $wgLanguageCode;
		}

		return $code;
	}

	/**
	 * @param Language|string $language Language instance or language code
	 * @throws MWException
	 * @since 1.19
	 */
	public function setLanguage( $language ) {
		if ( $language instanceof Language ) {
			$this->lang = $language;
		} elseif ( is_string( $language ) ) {
			$language = self::sanitizeLangCode( $language );
			$obj = MediaWikiServices::getInstance()->getLanguageFactory()->getLanguage( $language );
			$this->lang = $obj;
		} else {
			throw new MWException( __METHOD__ . " was passed an invalid type of data." );
		}
	}

	/**
	 * Get the Language object.
	 * Initialization of user or request objects can depend on this.
	 * @return Language
	 * @throws Exception
	 * @since 1.19
	 */
	public function getLanguage() {
		if ( $this->languageRecursion === true ) {
			trigger_error( "Recursion detected in " . __METHOD__, E_USER_WARNING );
			$e = new Exception;
			wfDebugLog( 'recursion-guard', "Recursion detected:\n" . $e->getTraceAsString() );

			$code = $this->getConfig()->get( 'LanguageCode' ) ?: 'en';
			$this->lang = MediaWikiServices::getInstance()->getLanguageFactory()
				->getLanguage( $code );
		} elseif ( $this->lang === null ) {
			$this->languageRecursion = true;

			try {
				$request = $this->getRequest();
				$user = $this->getUser();

				// Optimisation: Avoid slow getVal(), this isn't user-generated content.
				$code = $request->getRawVal( 'uselang', 'user' );
				if ( $code === 'user' ) {
					$code = $user->getOption( 'language' );
				}

				// There are certain characters we don't allow in language code strings,
				// but by and large almost any valid UTF-8 string will makes it past
				// this check and the LanguageNameUtils::isValidCode method it uses.
				// This is to support on-wiki interface message overrides for
				// non-existent language codes. Also known as "Uselang hacks".
				// See <https://www.mediawiki.org/wiki/Manual:Uselang_hack>
				// For something like "en-whatever" or "de-whatever" it will end up
				// with a mostly "en" or "de" interface, but with an extra layer of
				// possible MessageCache overrides from `MediaWiki:*/<code>` titles.
				// While non-ASCII works here, it is required that they are in
				// NFC form given this will not convert to normalised form.
				$code = self::sanitizeLangCode( $code );

				Hooks::runner()->onUserGetLanguageObject( $user, $code, $this );

				if ( $code === $this->getConfig()->get( 'LanguageCode' ) ) {
					$this->lang = MediaWikiServices::getInstance()->getContentLanguage();
				} else {
					$obj = MediaWikiServices::getInstance()->getLanguageFactory()
						->getLanguage( $code );
					$this->lang = $obj;
				}
			} finally {
				$this->languageRecursion = false;
			}
		}

		return $this->lang;
	}

	/**
	 * @param Skin $skin
	 */
	public function setSkin( Skin $skin ) {
		$this->skin = clone $skin;
		$this->skin->setContext( $this );
	}

	/**
	 * @return Skin
	 */
	public function getSkin() {
		if ( $this->skin === null ) {
			$skin = null;
			Hooks::runner()->onRequestContextCreateSkin( $this, $skin );
			$factory = MediaWikiServices::getInstance()->getSkinFactory();

			if ( $skin instanceof Skin ) {
				// The hook provided a skin object
				$this->skin = $skin;
			} elseif ( is_string( $skin ) ) {
				// The hook provided a skin name
				// Normalize the key, just in case the hook did something weird.
				$normalized = Skin::normalizeKey( $skin );
				$this->skin = $factory->makeSkin( $normalized );
			} else {
				// No hook override, go through normal processing
				if ( !in_array( 'skin', $this->getConfig()->get( 'HiddenPrefs' ) ) ) {
					$userSkin = $this->getUser()->getOption( 'skin' );
					// Optimisation: Avoid slow getVal(), this isn't user-generated content.
					$userSkin = $this->getRequest()->getRawVal( 'useskin', $userSkin );
				} else {
					$userSkin = $this->getConfig()->get( 'DefaultSkin' );
				}

				// Normalize the key in case the user is passing gibberish query params
				// or has old user preferences (T71566).
				// Skin::normalizeKey will also validate it, so makeSkin() won't throw.
				$normalized = Skin::normalizeKey( $userSkin );
				$this->skin = $factory->makeSkin( $normalized );
			}

			// After all that set a context on whatever skin got created
			$this->skin->setContext( $this );
		}

		return $this->skin;
	}

	/**
	 * Get a Message object with context set
	 * Parameters are the same as wfMessage()
	 *
	 * @param string|string[]|MessageSpecifier $key Message key, or array of keys,
	 *   or a MessageSpecifier.
	 * @param mixed ...$params
	 * @return Message
	 */
	public function msg( $key, ...$params ) {
		return wfMessage( $key, ...$params )->setContext( $this );
	}

	/**
	 * Get the RequestContext object associated with the main request
	 *
	 * @return RequestContext
	 */
	public static function getMain() {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Get the RequestContext object associated with the main request
	 * and gives a warning to the log, to find places, where a context maybe is missing.
	 *
	 * @param string $func
	 * @return RequestContext
	 * @since 1.24
	 */
	public static function getMainAndWarn( $func = __METHOD__ ) {
		wfDebug( $func . ' called without context. ' .
			"Using RequestContext::getMain() for sanity" );

		return self::getMain();
	}

	/**
	 * Resets singleton returned by getMain(). Should be called only from unit tests.
	 */
	public static function resetMain() {
		if ( !( defined( 'MW_PHPUNIT_TEST' ) || defined( 'MW_PARSER_TEST' ) ) ) {
			throw new MWException( __METHOD__ . '() should be called only from unit tests!' );
		}
		self::$instance = null;
	}

	/**
	 * Export the resolved user IP, HTTP headers, user ID, and session ID.
	 * The result will be reasonably sized to allow for serialization.
	 *
	 * @return array
	 * @since 1.21
	 */
	public function exportSession() {
		$session = MediaWiki\Session\SessionManager::getGlobalSession();
		return [
			'ip' => $this->getRequest()->getIP(),
			'headers' => $this->getRequest()->getAllHeaders(),
			'sessionId' => $session->isPersistent() ? $session->getId() : '',
			'userId' => $this->getUser()->getId()
		];
	}

	/**
	 * Import an client IP address, HTTP headers, user ID, and session ID
	 *
	 * This sets the current session, $wgUser, and $wgRequest from $params.
	 * Once the return value falls out of scope, the old context is restored.
	 * This method should only be called in contexts where there is no session
	 * ID or end user receiving the response (CLI or HTTP job runners). This
	 * is partly enforced, and is done so to avoid leaking cookies if certain
	 * error conditions arise.
	 *
	 * This is useful when background scripts inherit context when acting on
	 * behalf of a user. In general the 'sessionId' parameter should be set
	 * to an empty string unless session importing is *truly* needed. This
	 * feature is somewhat deprecated.
	 *
	 * @note suhosin.session.encrypt may interfere with this method.
	 *
	 * @param array $params Result of RequestContext::exportSession()
	 * @return ScopedCallback
	 * @throws MWException
	 * @since 1.21
	 */
	public static function importScopedSession( array $params ) {
		if ( strlen( $params['sessionId'] ) &&
			MediaWiki\Session\SessionManager::getGlobalSession()->isPersistent()
		) {
			// Sanity check to avoid sending random cookies for the wrong users.
			// This method should only called by CLI scripts or by HTTP job runners.
			throw new MWException( "Sessions can only be imported when none is active." );
		} elseif ( !IPUtils::isValid( $params['ip'] ) ) {
			throw new MWException( "Invalid client IP address '{$params['ip']}'." );
		}

		if ( $params['userId'] ) { // logged-in user
			$user = User::newFromId( $params['userId'] );
			$user->load();
			if ( !$user->getId() ) {
				throw new MWException( "No user with ID '{$params['userId']}'." );
			}
		} else { // anon user
			$user = User::newFromName( $params['ip'], false );
		}

		$importSessionFunc = function ( User $user, array $params ) {
			global $wgRequest, $wgUser;

			$context = RequestContext::getMain();

			// Commit and close any current session
			if ( MediaWiki\Session\PHPSessionHandler::isEnabled() ) {
				session_write_close(); // persist
				session_id( '' ); // detach
				$_SESSION = []; // clear in-memory array
			}

			// Get new session, if applicable
			$session = null;
			if ( strlen( $params['sessionId'] ) ) { // don't make a new random ID
				$manager = MediaWiki\Session\SessionManager::singleton();
				$session = $manager->getSessionById( $params['sessionId'], true )
					?: $manager->getEmptySession();
			}

			// Remove any user IP or agent information, and attach the request
			// with the new session.
			$context->setRequest( new FauxRequest( [], false, $session ) );
			$wgRequest = $context->getRequest(); // b/c

			// Now that all private information is detached from the user, it should
			// be safe to load the new user. If errors occur or an exception is thrown
			// and caught (leaving the main context in a mixed state), there is no risk
			// of the User object being attached to the wrong IP, headers, or session.
			$context->setUser( $user );
			$wgUser = $context->getUser(); // b/c
			if ( $session && MediaWiki\Session\PHPSessionHandler::isEnabled() ) {
				session_id( $session->getId() );
				AtEase::quietCall( 'session_start' );
			}
			$request = new FauxRequest( [], false, $session );
			$request->setIP( $params['ip'] );
			foreach ( $params['headers'] as $name => $value ) {
				$request->setHeader( $name, $value );
			}
			// Set the current context to use the new WebRequest
			$context->setRequest( $request );
			$wgRequest = $context->getRequest(); // b/c
		};

		// Stash the old session and load in the new one
		$oUser = self::getMain()->getUser();
		$oParams = self::getMain()->exportSession();
		$oRequest = self::getMain()->getRequest();
		$importSessionFunc( $user, $params );

		// Set callback to save and close the new session and reload the old one
		return new ScopedCallback(
			function () use ( $importSessionFunc, $oUser, $oParams, $oRequest ) {
				global $wgRequest;
				$importSessionFunc( $oUser, $oParams );
				// Restore the exact previous Request object (instead of leaving FauxRequest)
				RequestContext::getMain()->setRequest( $oRequest );
				$wgRequest = RequestContext::getMain()->getRequest(); // b/c
			}
		);
	}

	/**
	 * Create a new extraneous context. The context is filled with information
	 * external to the current session.
	 * - Title is specified by argument
	 * - Request is a FauxRequest, or a FauxRequest can be specified by argument
	 * - User is an anonymous user, for separation IPv4 localhost is used
	 * - Language will be based on the anonymous user and request, may be content
	 *   language or a uselang param in the fauxrequest data may change the lang
	 * - Skin will be based on the anonymous user, should be the wiki's default skin
	 *
	 * @param Title $title Title to use for the extraneous request
	 * @param WebRequest|array $request A WebRequest or data to use for a FauxRequest
	 * @return RequestContext
	 */
	public static function newExtraneousContext( Title $title, $request = [] ) {
		$context = new self;
		$context->setTitle( $title );
		if ( $request instanceof WebRequest ) {
			$context->setRequest( $request );
		} else {
			$context->setRequest( new FauxRequest( $request ) );
		}
		$context->user = User::newFromName( '127.0.0.1', false );

		return $context;
	}
}
