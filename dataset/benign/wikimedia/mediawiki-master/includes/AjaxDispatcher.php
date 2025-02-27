<?php
/**
 * Handle ajax requests and send them to the proper handler.
 *
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
 * @file
 * @ingroup Ajax
 */

use MediaWiki\MediaWikiServices;

// Use superglobals, but since it's deprecated, it's not worth fixing
// phpcs:disable MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals

/**
 * @defgroup Ajax Ajax
 */

/**
 * Object-Oriented Ajax functions.
 * @ingroup Ajax
 */
class AjaxDispatcher {
	/**
	 * The way the request was made, either a 'get' or a 'post'
	 * @var string
	 */
	private $mode;

	/**
	 * Name of the requested handler
	 * @var string
	 */
	private $func_name;

	/** Arguments passed
	 * @var array
	 */
	private $args;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * Load up our object with user supplied data
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;

		$this->mode = "";

		if ( !empty( $_GET["rs"] ) ) {
			$this->mode = "get";
		}

		if ( !empty( $_POST["rs"] ) ) {
			$this->mode = "post";
		}

		switch ( $this->mode ) {
			case 'get':
				$this->func_name = $_GET["rs"] ?? '';
				if ( !empty( $_GET["rsargs"] ) ) {
					$this->args = $_GET["rsargs"];
				} else {
					$this->args = [];
				}
				break;
			case 'post':
				$this->func_name = $_POST["rs"] ?? '';
				if ( !empty( $_POST["rsargs"] ) ) {
					$this->args = $_POST["rsargs"];
				} else {
					$this->args = [];
				}
				break;
			default:
				return;
				# Or we could throw an exception:
				# throw new MWException( __METHOD__ . ' called without any data (mode empty).' );
		}
	}

	/**
	 * Pass the request to our internal function.
	 * BEWARE! Data are passed as they have been supplied by the user,
	 * they should be carefully handled in the function processing the
	 * request.
	 *
	 * phan-taint-check triggers as it is not smart enough to understand
	 * the early return if func_name not in AjaxExportList.
	 * @suppress SecurityCheck-XSS
	 * @param User $user
	 */
	public function performAction( User $user ) {
		if ( empty( $this->mode ) ) {
			return;
		}

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !in_array( $this->func_name, $this->config->get( 'AjaxExportList' ) ) ) {
			wfDebug( __METHOD__ . ' Bad Request for unknown function ' . $this->func_name );
			wfHttpError(
				400,
				'Bad Request',
				"unknown function " . $this->func_name
			);
		} elseif ( !$permissionManager->isEveryoneAllowed( 'read' ) &&
				   !$permissionManager->userHasRight( $user, 'read' ) ) {
			wfHttpError(
				403,
				'Forbidden',
				'You are not allowed to view pages.' );
		} else {
			wfDebug( __METHOD__ . ' dispatching ' . $this->func_name );
			try {
				$result = call_user_func_array( $this->func_name, $this->args );

				if ( $result === false || $result === null ) {
					wfDebug( __METHOD__ . ' ERROR while dispatching ' .
						$this->func_name . "(" . var_export( $this->args, true ) . "): " .
						"no data returned" );

					wfHttpError( 500, 'Internal Error',
						"{$this->func_name} returned no data" );
				} else {
					if ( is_string( $result ) ) {
						$result = new AjaxResponse( $result );
					}

					// Make sure DB commit succeeds before sending a response
					$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
					$lbFactory->commitMasterChanges( __METHOD__ );

					$result->sendHeaders();
					$result->printText();

					wfDebug( __METHOD__ . ' dispatch complete for ' . $this->func_name );
				}
			} catch ( Exception $e ) {
				wfDebug( __METHOD__ . ' ERROR while dispatching ' .
					$this->func_name . "(" . var_export( $this->args, true ) . "): " .
					get_class( $e ) . ": " . $e->getMessage() );

				if ( !headers_sent() ) {
					wfHttpError( 500, 'Internal Error',
						$e->getMessage() );
				} else {
					print $e->getMessage();
				}
			}
		}
	}
}
