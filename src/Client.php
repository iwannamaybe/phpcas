<?php

namespace Iwannamaybe\PhpCas;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Contracts\Logging\Log;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Iwannamaybe\PhpCas\Exceptions\AuthenticationException;
use Iwannamaybe\PhpCas\Exceptions\CasInvalidArgumentException;
use Iwannamaybe\PhpCas\ProxyChain\ProxyChainAllowedList;
use Iwannamaybe\PhpCas\Requests\CurlMultiRequest;
use Iwannamaybe\PhpCas\Requests\CurlRequest;
use Iwannamaybe\PhpCas\Requests\MultiRequestInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

class Client
{
	/**
	 * @var Repository $_Config config manager
	 */
	private $_Config;

	/**
	 * @var UrlGenerator $_Url url manager
	 */
	private $_Url;

	/**
	 * @var Request $_Request request manager
	 */
	private $_Request;

	/**
	 * @var Redirector $_Redirect
	 */
	private $_Redirect;

	/**
	 * @var Log $_Logger log manager
	 */
	private $_Logger;

	/**
	 * A translator to ... er ... translate stuff.
	 *
	 * @var \Symfony\Component\Translation\TranslatorInterface
	 */
	private static $translator;

	private $requestUri;
	private $ticket;
	private $guard;
	private $CasConfig;
	private $isProxy;
	private $callbackUri;

	/**
	 * @var string $_user The Authenticated user. Written by CAS_Client::_setUser(), read by Client::getUser().
	 */
	private $user = '';

	/**
	 * @var array $attributes user attributes
	 */
	private $attributes = [];

	private $allowedProxyChains;

	/**
	 * @var array $_proxies This array will store a list of proxies in front of this application. This property will only be populated if this script is being proxied rather than accessed directly.It is set in CAS_Client::validateCAS20() and can be read by Client::getProxies()
	 */
	private $proxies = [];

	/**
	 * @var bool $rebroadcast whether to rebroadcast pgtIou/pgtId and logoutRequest
	 */
	private $rebroadcast = false;
	/**
	 * @var array $rebroadcastNodes array of the rebroadcast nodes
	 */
	private $rebroadcastNodes = [];

	/**
	 * @param array $rebroadcastHeaders An array to store extra rebroadcast curl options.
	 */
	private $rebroadcastHeaders = [];

	/**
	 * @var string $pgt the Proxy Grnting Ticket given by the CAS server (empty otherwise). Written by CAS_Client::_setPGT(), read by Client::getPGT() and Client::hasPGT()
	 */
	private $pgt = '';

	/**
	 * @var boolean $clearTicketFromUri If true, phpCAS will clear session tickets from the URL after a successful authentication.
	 */
	private $clearTicketFromUri = true;

	/**
	 * @var array $curlOptions An array to store extra curl options.
	 */
	private $curlOptions = [];

	/**
	 * @var string $requestImplementation The class to instantiate for making web requests in readUrl(). The class specified must implement the RequestInterface. By default CurlRequest is used, but this may be overridden to supply alternate request mechanisms for testing.
	 */
	private $requestImplementation = CurlRequest::class;

	/**
	 * @var string $multiRequestImplementation The class to instantiate for making web requests in readUrl(). The class specified must implement the RequestInterface. By default CurlRequest is used, but this may be overridden to supply alternate request mechanisms for testing.
	 */
	private $multiRequestImplementation = CurlMultiRequest::class;

	public function __construct(Repository $config, UrlGenerator $url, Request $request, Log $logger, Redirector $redirect)
	{
		$this->_Config   = $config;
		$this->_Url      = $url;
		$this->_Request  = $request;
		$this->_Logger   = $logger;
		$this->_Redirect = $redirect;
	}

	/**
	 * @param string $guard
	 * @return $this
	 */
	public function setGuard(string $guard)
	{
		$this->guard     = $guard;
		$this->CasConfig = $this->_Config->get("phpcas.{$guard}");
		return $this;
	}

	/**
	 * init cas validate uri
	 *
	 * @return string
	 */
	private function getValidateUri()
	{
		switch ($this->getVersion()) {
			case CasConst::CAS_VERSION_1_0:
				return '/validate';
				break;
			case CasConst::CAS_VERSION_2_0:
				return '/serviceValidate';
				break;
			case CasConst::CAS_VERSION_3_0:
				return '/p3/serviceValidate';
				break;
			default:
				throw new CasInvalidArgumentException('not support version');
		}
	}

	/**
	 * init cas proxy validate uri
	 *
	 * @return string
	 */
	private function getProxyValidateUri()
	{
		switch ($this->getVersion()) {
			case CasConst::CAS_VERSION_1_0:
				return '';
				break;
			case CasConst::CAS_VERSION_2_0:
				return 'proxyValidate';
				break;
			case CasConst::CAS_VERSION_3_0:
				return 'p3/proxyValidate';
				break;
			default:
				throw new CasInvalidArgumentException('not support version');
		}
	}

	/**
	 * This method is used to retrieve the SAML validating URL of the CAS server.
	 *
	 * @param bool $renew
	 *
	 * @return string samlValidate URL.
	 */
	public function getSamlValidateUri($renew = false)
	{
		$params['TARGET'] = $this->getRequestUri();
		if ($renew) {
			$params['renew'] = 'true';
		}
		$cas_saml_validate_uri = $this->getVersion() == CasConst::SAML_VERSION_1_1 ? 'samlValidate' : '';
		return $this->buildUri($this->CasConfig['cas_server'] . $cas_saml_validate_uri, $params);
	}

	/**
	 * Get phpcas login uri
	 *
	 * @param string $redirect redirect back uri
	 * @param bool   $gateway
	 * @param bool   $renew
	 *
	 * @return string
	 */
	public function getLoginUri($redirect = null, $gateway = false, $renew = false)
	{
		if ($this->CasConfig['fake']) {
			return $this->buildUri($this->getRequestUri(), [$this->CasConfig['ticket_key'] => 'ST-123-2345678910']);
		}
		$params = [
			'service' => $this->getRedirectUri($redirect),
			'channel' => $this->CasConfig['channel']
		];
		if ($gateway) {
			$params['gateway'] = 'true';
		}
		if ($renew) {
			$params['renew'] = 'true';
		}

		$url = $this->buildUri($this->CasConfig['server'] . $this->CasConfig['login_uri'], $params);
		$this->_Logger->debug('【单点登录】登录地址', ['url' => $url]);
		return $url;
	}

	/**
	 * Get phpcas logout uri
	 *
	 * @param string $redirect redirect back uri
	 *
	 * @return string
	 */
	public function getLogoutUri($redirect = null)
	{
		if ($this->CasConfig['fake']) {
			return $this->buildUri($this->getRequestUri());
		}

		$url = $this->buildUri($this->CasConfig['server'] . $this->CasConfig['logout_uri'], [
			'service' => $this->getRedirectUri($redirect),
			'channel' => $this->CasConfig['channel']
		]);
		$this->_Logger->debug('【单点登录】注销地址', ['url' => $url]);
		return $url;
	}

	/**
	 * get the find password uri
	 *
	 * @return string
	 */
	public function getFindPasswordUri()
	{
		return $this->CasConfig['server'] . $this->CasConfig['find_password_uri'];
	}

	/**
	 * This method returns the Service Ticket provided in the URL of the request.
	 *
	 * @return string service ticket.
	 */
	public function getTicket()
	{
		if (empty($this->ticket)) {
			$ticket = $this->_Request->get($this->CasConfig['ticket_key'], null);
			if (preg_match('/^[SP]T-/', $ticket)) {
				$this->ticket = $ticket;
				$this->_Logger->debug("Ticket `{$ticket}` found");
			} elseif (!empty($ticket)) {
				$this->_Logger->debug('ill-formed ticket found in the URL (' . $this->CasConfig['ticket_key'] . '=`' . htmlentities($ticket) . '`)');
			}
		}
		return $this->ticket;
	}

	/**
	 * This method tells if a Service Ticket was stored.
	 *
	 * @return bool if a Service Ticket has been stored.
	 */
	public function hasTicket()
	{
		return !empty($this->getTicket());
	}

	/**
	 * get cas server version
	 *
	 * @return string
	 */
	public function getVersion()
	{
		return $this->CasConfig['version'];
	}

	/**
	 * This method is used to set additional user curl options.
	 *
	 * @param string $key   name of the curl option
	 * @param string $value value of the curl option
	 *
	 * @return void
	 */
	public function setExtraCurlOption($key, $value)
	{
		$this->curlOptions[$key] = $value;
	}

	/**
	 * Set the Proxy array, probably from persistant storage.
	 *
	 * @param array $proxies An array of proxies
	 */
	private function setProxies($proxies)
	{
		$this->proxies = $proxies;
		if (!empty($proxies)) {
			// For proxy-authenticated requests people are not viewing the URL directly since the client is another application making a web-service call. Because of this, stripping the ticket from the URL is unnecessary and causes another web-service request to be performed. Additionally, if session handling on either the client or the server malfunctions then the subsequent request will not complete successfully.
			$this->setClearTicketFromUri();
		}
	}

	/**
	 * Answer an array of proxies that are sitting in front of this application. This method will only return a non-empty array if we have received and validated a Proxy Ticket.
	 *
	 * @return array
	 * @access public
	 */
	public function getProxies()
	{
		return $this->proxies;
	}

	/**
	 * Configure the client to not send redirect headers and call exit() on authentication success. The normal redirect is used to remove the service ticket from the client's URL, but for running unit tests we need to continue without exiting. Needed for testing authentication
	 *
	 * @param bool $value
	 *
	 * @return void
	 */
	public function setClearTicketFromUri($value = true)
	{
		$this->clearTicketFromUri = $value;
	}

	public function getClearTicketFromUri()
	{
		return $this->clearTicketFromUri;
	}

	/**
	 * This method returns the Proxy Granting Ticket given by the CAS server.
	 *
	 * @return string the Proxy Granting Ticket.
	 */
	private function getPGT()
	{
		return $this->pgt;
	}

	/**
	 * This method sets the CAS user's login name.
	 *
	 * @param string $user the login name of the authenticated user.
	 *
	 * @return void
	 */
	private function setUser($user)
	{
		$this->user = $user;
	}

	/**
	 * This method returns the CAS user's login name.
	 *
	 * @return string the login name of the authenticated user
	 * @warning should be called only after Client::forceAuthentication() or Client::isAuthenticated(), otherwise halt with an error.
	 */
	public function getUser()
	{
		return $this->user;
	}

	/**
	 * Set an array of attributes
	 *
	 * @param array $attributes a key value array of attributes
	 */
	public function setAttributes($attributes)
	{
		$this->attributes = $attributes;
	}

	/**
	 * This method stores the Proxy Granting Ticket.
	 *
	 * @param string $pgt The Proxy Granting Ticket.
	 *
	 * @return void
	 */
	private function setPGT($pgt)
	{
		$this->pgt = $pgt;
	}

	/**
	 * Tells if a CAS client is a CAS proxy or not
	 *
	 * @return bool true when the CAS client is a CAs proxy, false otherwise
	 */
	public function isProxy()
	{
		return $this->isProxy;
	}

	/**
	 * check if the user is authenticated (previously or by tickets given in the uri)
	 *
	 * @param bool $renew true to force the authentication with the CAS server
	 *
	 * @return true when the user is authenticated. Also may redirect to the same URL without the ticket.
	 */
	public function isAuthenticated($renew = false)
	{
		if ($this->CasConfig['fake']) {
			$this->setUser($this->CasConfig['fake_user_id']);
			$this->_Logger->warning("CAS 1.0 faked ticket '{$this->getTicket()}' was validated");
			return true;
		}
		switch ($this->getVersion()) {
			case CasConst::CAS_VERSION_1_0:
				$this->_Logger->debug("CAS {$this->getVersion()} ticket '{$this->getTicket()}' is present");
				$this->validateCAS10($validate_url, $text_response, $renew); // if it fails, it halts
				$this->_Logger->debug("CAS {$this->getVersion()} ticket '{$this->getTicket()}' was validated");
				return true;
			case CasConst::CAS_VERSION_2_0:
			case CasConst::CAS_VERSION_3_0:
				$this->_Logger->debug("CAS {$this->getVersion()} ticket `{$this->getTicket()}` is present");
				$this->validateCAS20($validate_url, $text_response, $tree_response, $renew); // note: if it fails, it halts
				$this->_Logger->debug("CAS {$this->getVersion()} ticket `{$this->getTicket()}` was validated");
				if ($this->isProxy()) {
					$this->validatePGT($validate_url, $text_response, $tree_response); // idem
					$this->_Logger->debug("PGT `{$this->getPGT()}` was validated");
					$this->_Request->session()->put($this->CasConfig['session_key'] . '.pgt', $this->getPGT());
				}
				if (!empty($proxies = $this->getProxies())) {
					$this->_Request->session()->put($this->CasConfig['session_key'] . '.proxies', $proxies);
				}
				return true;
			case CasConst::SAML_VERSION_1_1:
				$this->_Logger->debug("SAML {$this->getVersion()} ticket `{$this->getTicket()}` is present");
				$this->validateSA($validate_url, $text_response, $tree_response, $renew); // if it fails, it halts
				$this->_Logger->debug("SAML {$this->getVersion()} ticket `{$this->getTicket()}` was validated");
				return true;
			default:
				$this->_Logger->debug('Protocoll error');
				throw new CasInvalidArgumentException('Protocoll error');
		}
	}

	/**
	 * This method is used to validate a PGT; halt on failure.
	 *
	 * @param string     &$validate_url the URL of the request to the CAS server.
	 * @param string     $text_response the response of the CAS server, as is (XML text); result of Client::validateCAS10() or Client::validateCAS20().
	 * @param DOMElement $tree_response the response of the CAS server, as a DOM XML tree; result of Client::validateCAS10() or Client::validateCAS20().
	 *
	 * @return bool true when successfull and issue a AuthenticationException and false on an error
	 */
	private function validatePGT(&$validate_url, $text_response, $tree_response)
	{
		if ($tree_response->getElementsByTagName("proxyGrantingTicket")->length == 0) {
			$this->_Logger->debug('<proxyGrantingTicket> not found');
			// authentication succeded, but no PGT Iou was transmitted
			throw new AuthenticationException(
				$this, 'Ticket validated but no PGT Iou transmitted',
				$validate_url, false/*$no_response*/, false/*$bad_response*/,
				$text_response
			);
		} else {
			// PGT Iou transmitted, extract it
			$pgt_iou = trim($tree_response->getElementsByTagName("proxyGrantingTicket")->item(0)->nodeValue);
			if (preg_match('/PGTIOU-[\.\-\w]/', $pgt_iou)) {
				$pgt = $this->loadPGT($pgt_iou);
				if ($pgt == false) {
					$this->_Logger->notice('could not load PGT');
					throw new AuthenticationException(
						$this,
						'PGT Iou was transmitted but PGT could not be retrieved',
						$validate_url,
						false,
						false,
						$text_response
					);
				}
				$this->setPGT($pgt);
			} else {
				$this->_Logger->debug('PGTiou format error');
				throw new AuthenticationException(
					$this,
					'PGT Iou was transmitted but has wrong format',
					$validate_url,
					false,
					false,
					$text_response
				);
			}
		}

		return true;
	}

	/**
	 * Initialize the translator instance if necessary.
	 *
	 * @return \Symfony\Component\Translation\TranslatorInterface
	 */
	protected static function translator()
	{
		if (static::$translator === null) {
			static::$translator = new Translator('en');
			static::$translator->addLoader('array', new ArrayLoader());
			static::setLocale('en');
		}

		return static::$translator;
	}

	/**
	 * Get the translator instance in use
	 *
	 * @return \Symfony\Component\Translation\TranslatorInterface
	 */
	public static function getTranslator()
	{
		return static::translator();
	}

	/**
	 * Set the current translator locale and indicate if the source locale file exists
	 *
	 * @param string $locale
	 *
	 * @return bool
	 */
	public static function setLocale($locale)
	{
		$locale = preg_replace_callback('/\b([a-z]{2})[-_](?:([a-z]{4})[-_])?([a-z]{2})\b/', function ($matches) {
			return $matches[1] . '_' . (!empty($matches[2]) ? ucfirst($matches[2]) . '_' : '') . strtoupper($matches[3]);
		}, strtolower($locale));
		if (file_exists($filename = __DIR__ . '/Lang/' . $locale . '.php')) {
			static::translator()->setLocale($locale);
			// Ensure the locale has been loaded.
			static::translator()->addResource('array', require $filename, $locale);

			return true;
		}

		return false;
	}

	/**
	 * This method is used to append query parameters to an url. Since the url might already contain parameter it has to be detected and to build a proper Uri
	 *
	 * @param string $url    base url to add the query params to
	 * @param array  $params params in query form with & separated
	 *
	 * @return string uri with query params
	 */
	public function buildUri($url, array $params = [])
	{
		if (empty($params)) {
			return $url;
		}
		$seperate = (strstr($url, '?') === false) ? '?' : '&';
		$query    = http_build_query($params);

		return $url . $seperate . $query;
	}

	/**
	 * This method is used to validate a CAS 1,0 ticket; halt on failure, and sets $validate_url, $text_reponse and $tree_response on success.
	 *
	 * @param string &$validate_url  reference to the the URL of the request to the CAS server.
	 * @param string &$text_response reference to the response of the CAS server, as is (XML text).
	 * @param bool   $renew          true to force the authentication with the CAS server
	 *
	 * @return bool true when successfull and issue a AuthenticationException and false on an error
	 */
	protected function validateCAS10(&$validate_url, &$text_response, $renew = false)
	{
		$query_params[$this->CasConfig['ticket_key']] = $this->getTicket();
		if ($renew) {
			$query_params['renew'] = 'true';
		}
		$validate_url = $this->buildUri($this->getTicketValidateUri(), $query_params);
		if (!$this->readUri($validate_url, $headers, $text_response, $err_msg)) {
			$this->_Logger->notice("could not open URL '{$validate_url}' to validate ({$err_msg})");
			throw new AuthenticationException(
				$this,
				'CAS 1.0 ticket not validated',
				$validate_url,
				true
			);
		}
		if (preg_match('/^no\n/', $text_response)) {
			$this->_Logger->debug('Ticket has not been validated');
			throw new AuthenticationException(
				$this,
				'ST not validated',
				$validate_url,
				false,
				false,
				$text_response
			);
		} elseif (!preg_match('/^yes\n/', $text_response)) {
			$this->_Logger->debug('ill-formed response');
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				true,
				$text_response
			);
		}
		// ticket has been validated, extract the user name
		$arr = preg_split('/\n/', $text_response);
		$this->setUser(trim($arr[1]));
		return true;
	}

	/**
	 * This method is used to validate a cas 2.0 ST or PT; halt on failure Used for all CAS 2.0 validations
	 *
	 * @param string     $validate_url  the url of the reponse
	 * @param string     $text_response the text of the repsones
	 * @param DOMElement $tree_response the domxml tree of the respones
	 * @param bool       $renew         true to force the authentication with the CAS server and false on an error
	 *
	 * @throws AuthenticationException
	 * @return bool
	 */
	public function validateCAS20(&$validate_url, &$text_response, &$tree_response, $renew = false)
	{
		if ($this->getAllowedProxyChains()->isProxyingAllowed()) {
			$validate_base_url = $this->getProxyTicketValidateUri();
		} else {
			$validate_base_url = $this->getTicketValidateUri();
		}
		$query_params[$this->CasConfig['ticket_key']] = $this->getTicket();
		if ($this->isProxy()) {
			$query_params['pgtUrl'] = $this->getCallbackUri();
		}
		if ($renew) {
			$query_params['renew'] = 'true';
		}
		$validate_url = $this->buildUri($validate_base_url, $query_params);
		$this->_Logger->debug('【单点登录】构建验证地址', ['validate_base_url' => $validate_base_url, 'validate_url' => $validate_url]);
		if (!$this->readUri($validate_url, $headers, $text_response, $err_msg)) {
			$this->_Logger->notice('could not open URL \'' . $validate_url . '\' to validate (' . $err_msg . ')');
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				true
			);
		}
		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->encoding           = "utf-8";// CAS servers should only return data in utf-8
		if (!($dom->loadXML($text_response))) {
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				true,
				$text_response
			);
		} elseif (!($tree_response = $dom->documentElement)) {
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				true,
				$text_response
			);
		} elseif ($tree_response->localName != 'serviceResponse') {
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				true,
				$text_response
			);
		} elseif ($tree_response->getElementsByTagName("authenticationFailure")->length != 0) {
			$auth_fail_list = $tree_response->getElementsByTagName("authenticationFailure");
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				false,
				$text_response,
				$auth_fail_list->item(0)->getAttribute('code'),
				trim($auth_fail_list->item(0)->nodeValue)
			);
		} elseif ($tree_response->getElementsByTagName("authenticationSuccess")->length != 0) {
			$success_elements = $tree_response->getElementsByTagName("authenticationSuccess");
			if ($success_elements->item(0)->getElementsByTagName("user")->length == 0) {
				throw new AuthenticationException(
					$this,
					'Ticket not validated',
					$validate_url,
					false,
					true,
					$text_response
				);
			} else {
				$this->setUser(trim($success_elements->item(0)->getElementsByTagName("user")->item(0)->nodeValue));
				//$this->readExtraAttributesCas20($success_elements);
				// Store the proxies we are sitting behind for authorization checking
				$proxyList = [];
				if (sizeof($arr = $success_elements->item(0)->getElementsByTagName("proxy")) > 0) {
					foreach ($arr as $proxyElem) {
						$this->_Logger->debug('Found Proxy:' . $proxyElem->nodeValue);
						$proxyList[] = trim($proxyElem->nodeValue);
					}
					$this->setProxies($proxyList);
					$this->_Logger->debug('Storing Proxy List');
				}
				// Check if the proxies in front of us are allowed
				if (!$this->getAllowedProxyChains()->isProxyListAllowed($proxyList)) {
					throw new AuthenticationException(
						$this,
						'Proxy not allowed',
						$validate_url,
						false,
						true,
						$text_response
					);
				} else {
					return true;
				}
			}
		} else {
			throw new AuthenticationException(
				$this,
				'Ticket not validated',
				$validate_url,
				false,
				true,
				$text_response
			);
		}
	}

	/**
	 * This method is used to validate a SAML TICKET; halt on failure, and sets $validate_url, $text_reponse and $tree_response on success. These parameters are used later by CAS_Client::_validatePGT() for CAS proxies.
	 *
	 * @param string     &$validate_url  reference to the the URL of the request to the CAS server.
	 * @param string     &$text_response reference to the response of the CAS server, as is (XML text).
	 * @param DOMElement &$tree_response reference to the response of the CAS server, as a DOM XML tree.
	 * @param bool       $renew          true to force the authentication with the CAS server
	 *
	 * @return bool true when successfull and issue a AuthenticationException and false on an error
	 */
	public function validateSA(&$validate_url, &$text_response, &$tree_response, $renew = false)
	{
		$validate_url = $this->getSamlValidateUri($renew);
		if (!$this->readUri($validate_url, $headers, $text_response, $err_msg)) {
			$this->_Logger->notice("could not open URL `{$validate_url}` to validate ({$err_msg})");
			throw new AuthenticationException(
				$this,
				'SA not validated',
				$validate_url,
				true
			);
		}
		$this->_Logger->debug('server version: ' . $this->getVersion());
		if ($this->getVersion() == CasConst::SAML_VERSION_1_1) {
			$dom                     = new DOMDocument();
			$dom->preserveWhiteSpace = false;
			if (!($dom->loadXML($text_response))) {
				$this->_Logger->debug('dom->loadXML() failed');
				throw new AuthenticationException(
					$this,
					'SA not validated',
					$validate_url,
					false,
					true,
					$text_response
				);
			}
			if (!($tree_response = $dom->documentElement)) {
				$this->_Logger->debug('documentElement() failed');
				throw new AuthenticationException(
					$this,
					'SA not validated',
					$validate_url,
					false,
					true,
					$text_response
				);
			} elseif ($tree_response->localName != 'Envelope') {
				$this->_Logger->debug("bad XML root node (should be `Envelope` instead of `{$tree_response->localName}`");
				throw new AuthenticationException(
					$this,
					'SA not validated',
					$validate_url,
					false,
					true,
					$text_response
				);
			} elseif ($tree_response->getElementsByTagName("NameIdentifier")->length != 0) {
				$success_elements = $tree_response->getElementsByTagName("NameIdentifier");
				$this->_Logger->debug('NameIdentifier found');
				$user = trim($success_elements->item(0)->nodeValue);
				$this->_Logger->debug('user = `' . $user . '`');
				$this->setUser($user);
				$this->setSessionAttributes($text_response);
				return true;
			} else {
				$this->_Logger->debug('no <NameIdentifier> tag found in SAML payload');
				throw new AuthenticationException(
					$this,
					'SA not validated',
					$validate_url,
					false,
					true,
					$text_response
				);
			}
		}
		return false;
	}

	/**
	 * This method is used to acces a remote URL.
	 *
	 * @param string $url      the URL to access.
	 * @param string &$headers an array containing the HTTP header lines of the
	 *                         response (an empty array on failure).
	 * @param string &$body    the body of the response, as a string (empty on
	 *                         failure).
	 * @param string &$err_msg an error message, filled on failure.
	 *
	 * @return true on success, false otherwise (in this later case, $err_msg
	 * contains an error message).
	 */
	private function readUri($url, &$headers, &$body, &$err_msg)
	{
		/** @var CurlRequest $request */
		$request = new $this->requestImplementation;
		if (count($this->curlOptions)) {
			$request->setCurlOptions($this->curlOptions);
		}
		$request->setUrl($url);
		if ($this->CasConfig['cert_validate']) {
			if (empty($this->CasConfig['cert'])) {
				$this->_Logger->warning('one of the methods setCasCACert() or setNoCasCertValidation() must be called.');
			} else {
				$request->setSslCaCert($this->CasConfig['cert'], $this->CasConfig['cert_cn_validate']);
			}
		}
		if ($this->getVersion() == CasConst::SAML_VERSION_1_1) {
			$request->addHeader("soapaction: http://www.oasis-open.org/committees/security");
			$request->addHeader("cache-control: no-cache");
			$request->addHeader("pragma: no-cache");
			$request->addHeader("accept: text/xml");
			$request->addHeader("connection: keep-alive");
			$request->addHeader("content-type: text/xml");
			$request->makePost();
			$request->setPostBody($this->buildSAMLPayload());
		}
		if ($request->send()) {
			$headers = $request->getResponseHeaders();
			$body    = $request->getResponseBody();
			$err_msg = '';
			return true;
		} else {
			$headers = '';
			$body    = '';
			$err_msg = $request->getErrorMessage();
			return false;
		}
	}

	/**
	 * This method will parse the DOM and pull out the attributes from the SAML payload and put them into an array, then put the array into the session.
	 *
	 * @param string $text_response the SAML payload.
	 *
	 * @return bool true when successfull and false if no attributes a found
	 */
	private function setSessionAttributes($text_response)
	{
		$attr_array              = [];
		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;// Fix possible whitspace problems
		if (($dom->loadXML($text_response))) {
			$xPath = new DOMXpath($dom);
			$xPath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:1.0:protocol');
			$xPath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:1.0:assertion');
			$nodelist = $xPath->query("//saml:Attribute");
			if ($nodelist) {
				foreach ($nodelist as $node) {
					/** @var DOMElement $node */
					$xres        = $xPath->query("saml:AttributeValue", $node);
					$name        = $node->getAttribute("AttributeName");
					$value_array = [];
					foreach ($xres as $node2) {
						$value_array[] = $node2->nodeValue;
					}
					$attr_array[$name] = $value_array;
				}
				// UGent addition...
				foreach ($attr_array as $attr_key => $attr_value) {
					if (count($attr_value) > 1) {
						$this->attributes[$attr_key] = $attr_value;
						$this->_Logger->debug("* " . $attr_key . "=" . print_r($attr_value, true));
					} else {
						$this->attributes[$attr_key] = $attr_value[0];
						$this->_Logger->debug("* " . $attr_key . "=" . $attr_value[0]);
					}
				}

				return true;
			} else {
				$this->_Logger->debug("SAML Attributes are empty");
			}
		}

		return false;
	}

	/**
	 * This method is used to build the SAML POST body sent to /samlValidate URL.
	 *
	 * @return string SOAP-encased SAMLP artifact (the ticket).
	 */
	private function buildSAMLPayload()
	{
		$sa = urlencode($this->getTicket());

		return CasConst::SAML_SOAP_ENV . CasConst::SAML_SOAP_BODY . CasConst::SAMLP_REQUEST . CasConst::SAML_ASSERTION_ARTIFACT . $sa . CasConst::SAML_ASSERTION_ARTIFACT_CLOSE . CasConst::SAMLP_REQUEST_CLOSE . CasConst::SAML_SOAP_BODY_CLOSE . CasConst::SAML_SOAP_ENV_CLOSE;
	}

	/**
	 * Determine if the request has a URI that should pass through CSRF verification.
	 *
	 * @return bool
	 */
	protected function inExceptArray()
	{
		if (starts_with($this->_Url->previous(), $this->CasConfig['server'])) {
			return false;
		}
		foreach ($this->CasConfig['except']['url'] as $except) {
			if ($except !== '/') {
				$except = trim($except, '/');
			}
			if ($this->_Request->fullUrlIs($except) || $this->_Request->is($except)) {
				return true;
			}
		}
		$current_route_name = $this->_Request->route()->getName();
		foreach ($this->CasConfig['except']['route'] as $except) {
			if ($current_route_name == $except) {
				return true;
			}
			if (str_finish($except, '*')) {
				$except = rtrim($except, '*');
				$except = rtrim($except, '.');
				if (starts_with($current_route_name, $except)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * This method returns the URL of the current request (without any ticket CGI parameter).
	 *
	 * @param bool $withoutChannel
	 * @param bool $withoutTicket
	 *
	 * @return string URL
	 */
	public function getRequestUri(bool $withoutChannel = true, bool $withoutTicket = true)
	{
		if (empty($this->requestUri)) {
			if ($this->inExceptArray()) {
				$this->requestUri = $this->_Url->previous();
			} else {
				$this->requestUri = $this->_Request->fullUrl();
			}
		}
		$request_array = explode('?', $this->requestUri, 2);
		$query_params  = [];
		if (isset($request_array[1])) {
			parse_str($request_array[1], $query_params);
		}
		if ($withoutChannel) {
			unset($query_params['channel']);
		}
		if ($withoutTicket) {
			unset($query_params[$this->CasConfig['ticket_key']]);
		}
		return $this->buildUri(rtrim($request_array[0], '/'), $query_params);
	}

	public function getRedirectUri($redirect = null)
	{
		return $redirect ?? $this->getRequestUri();
	}

	/**
	 * This method returns the URL that should be used for the PGT callback (infact the URL of the current request without any CGI parameter, except if phpCAS::setFixedCallbackURL() was used).
	 *
	 * @return string callback URL
	 */
	private function getCallbackUri()
	{
		if (empty($this->callbackUri)) {
			$request_array     = explode('?', $this->_Url->current(), 1);
			$this->callbackUri = $request_array[0];
		}
		return $this->callbackUri;
	}

	/**
	 * Answer the ProxyChainAllowedList object for this client.
	 *
	 * @return ProxyChainAllowedList
	 */
	public function getAllowedProxyChains()
	{
		if (empty($this->allowedProxyChains)) {
			$this->allowedProxyChains = new ProxyChainAllowedList();
		}
		return $this->allowedProxyChains;
	}

	/**
	 * This method is used to retrieve the service validating URL of the CAS server.
	 *
	 * @return string serviceValidate Uri
	 */
	protected function getTicketValidateUri()
	{
		return $this->buildUri($this->CasConfig['server'] . $this->getValidateUri(), [
			'service' => $this->getRequestUri()
		]);
	}

	/**
	 * This method is used to retrieve the proxy validating URL of the CAS server.
	 *
	 * @return string proxy validate Uri.
	 */
	public function getProxyTicketValidateUri()
	{
		return $this->buildUri($this->CasConfig['server'] . $this->getProxyValidateUri(), [
			'service' => $this->getRequestUri()
		]);
	}

	public function handLoginRequest(callable $callback, $renew = false)
	{
		//isAuthenticated()验证为false时会自动抛出异常
		if ($this->isAuthenticated($renew)) {
			$callback($this->getUser());
		}
		return $this->_Redirect->to($this->getRequestUri());
	}

	public function isLogoutRequest()
	{
		return $this->_Request->has('logoutRequest');
	}

	public function handLogoutRequest($checkClient = true, $allowedClients = [])
	{
		if ($this->isLogoutRequest()) {
			$this->_Logger->debug("登出请求来过");
			$decoded_logout_rq = urldecode($_POST['logoutRequest']);
			$this->_Logger->debug("SAML REQUEST: " . $decoded_logout_rq);
			$allowed   = false;
			$client_ip = $this->_Request->ip();
			$client    = gethostbyaddr($client_ip);
			if ($checkClient) {
				if (empty($allowedClients)) {
					$allowedClients = [$this->CasConfig['server']];//TODO:验证服务器地址
				}
				$this->_Logger->debug("Client: {$client}/{$client_ip}");
				foreach ($allowedClients as $allowedClient) {
					if (($client == $allowedClient) || ($client_ip == $allowedClient)) {
						$this->_Logger->debug("Allowed client `{$allowedClient}` matches, logout request is allowed");
						$allowed = true;
						break;
					} else {
						$this->_Logger->debug("Allowed client `{$allowedClient}` does not match");
					}
				}
			} else {
				$this->_Logger->debug("No access control set");
				$allowed = true;
			}
			// If Logout command is permitted proceed with the logout
			if ($allowed) {
				$this->_Logger->debug("Logout command allowed");
				// Rebroadcast the logout request
				//TODO:广播未监测
				if ($this->rebroadcast && !isset($_POST['rebroadcast'])) {
					$this->rebroadcast(CasConst::LOGOUT);
				}
				// Extract the ticket from the SAML Request
				/*
				preg_match("|<samlp:SessionIndex>(.*)</samlp:SessionIndex>|", $decoded_logout_rq, $tick, PREG_OFFSET_CAPTURE, 3);
				$wrappedSamlSessionIndex = preg_replace('|<samlp:SessionIndex>|', '', $tick[0][0]);
				$ticket2logout = preg_replace('|</samlp:SessionIndex>|', '', $wrappedSamlSessionIndex);
				$this->_Logger->info("Ticket to logout: {$ticket2logout}");
				*/
				Auth::logout();
				$this->_Request->session()->migrate(true);

				// If phpCAS is managing the session_id, destroy session thanks to
				// session_id.
				/*
				if ($this->getChangeSessionID()) {
					$session_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $ticket2logout);
					phpCAS::trace("Session id: ".$session_id);

					// destroy a possible application session created before phpcas
					if (session()->getId() !== "") {
						session()->invalidate();
					}
					// fix session ID
					//session()->setId($session_id);
					$_COOKIE[session()->getName()]=$session_id;
					$_GET[session()->getName()]=$session_id;

					// Overwrite session
					session()->invalidate();
				}
				*/
			} else {
				$this->_Logger->notice("Unauthorized logout request from client '" . $client . "'");
			}
		}
	}

	/**
	 * This method rebroadcasts logout/pgtIou requests. Can be LOGOUT,PGTIOU
	 *
	 * @param int $type type of rebroadcasting.
	 *
	 * @return void
	 */
	private function rebroadcast($type)
	{
		// Try to determine the IP address of the server
		if (empty($ip = $this->_Request->server('SERVER_ADDR'))) {
			// IIS 7
			$ip = $this->_Request->server('LOCAL_ADDR');
		}
		// Try to determine the DNS name of the server
		if (!empty($ip)) {
			$dns = gethostbyaddr($ip);
		}
		/** @var MultiRequestInterface $multiRequest */
		$multiRequest = new $this->multiRequestImplementation();

		for ($i = 0; $i < sizeof($this->rebroadcastNodes); $i++) {
			if ((($this->getNodeType($this->rebroadcastNodes[$i]) == CasConst::HOSTNAME) && !empty($dns) && (stripos($this->rebroadcastNodes[$i], $dns) === false)) || (($this->getNodeType($this->rebroadcastNodes[$i]) == CasConst::IP) && !empty($ip) && (stripos($this->rebroadcastNodes[$i], $ip) === false))) {
				$this->_Logger->debug('Rebroadcast target URL: ' . $this->rebroadcastNodes[$i] . $this->_Request->server('REQUEST_URI'));
				/** @var CurlRequest $request */
				$request = new $this->requestImplementation();
				$url = $this->rebroadcastNodes[$i] . $this->_Request->server('REQUEST_URI');
				$request->setUrl($url);
				if (count($this->rebroadcastHeaders)) {
					$request->addHeaders($this->rebroadcastHeaders);
				}
				$request->makePost();
				if ($type == CasConst::LOGOUT) {
					// Logout request
					$request->setPostBody('rebroadcast=false&logoutRequest=' . $_POST['logoutRequest']);
				} elseif ($type == CasConst::PGTIOU) {
					// pgtIou/pgtId rebroadcast
					$request->setPostBody('rebroadcast=false');
				}
				$request->setCurlOptions([
					CURLOPT_FAILONERROR    => 1,
					CURLOPT_FOLLOWLOCATION => 1,
					CURLOPT_RETURNTRANSFER => 1,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_TIMEOUT        => 4
				]);
				$multiRequest->addRequest($request);
			} else {
				$this->_Logger->debug("Rebroadcast not sent to self: {$this->rebroadcastNodes[$i]} ==" . (empty($ip) ? '' : $ip) . '/' . (empty($dns) ? '' : $dns));
			}
		}
		// We need at least 1 request
		if ($multiRequest->getNumRequests() > 0) {
			$multiRequest->send();
		}
	}

	/**
	 * Determine the node type from the URL.
	 *
	 * @param String $nodeURL The node URL.
	 *
	 * @return string hostname
	 */
	private function getNodeType($nodeURL)
	{
		if (preg_match("/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/", $nodeURL)) {
			return CasConst::IP;
		} else {
			return CasConst::HOSTNAME;
		}
	}
}