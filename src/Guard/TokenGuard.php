<?php

namespace Webspot\Firewall\Guard;

use Psecio\Jwt;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Webspot\Firewall\Event\CreateTokenEvent;
use Webspot\Firewall\Event\SignOffEvent;
use Webspot\Firewall\Event\TokenValidatedEvent;
use Webspot\Firewall\Event\TokenValidationEvent;
use Webspot\Firewall\Event\ValidationEvent;
use Webspot\Firewall\Exception\ForbiddenException;
use Webspot\Firewall\Firewall;

class TokenGuard implements GuardInterface
{
    const TOKEN_FIREWALL_PASS_CLAIM = 'fwl:pass';
    const TOKEN_RENEW_AFTER = 'fwl:rna';

    /** {@inheritdoc} */
    public static function getSubscribedEvents()
    {
        return [
            Firewall::EVENT_VALIDATE_VISITOR => ['validateVisitor', 1024],
            Firewall::EVENT_VALIDATE_TOKEN => ['validateToken', 0],
            Firewall::EVENT_VISITOR_ALLOWED => ['visitorAllowed', 0],
            Firewall::EVENT_SIGNOFF => ['signOff', 0],
            Firewall::EVENT_CREATE_TOKEN => ['createToken', 0],
        ];
    }

    /** @var  EventDispatcher */
    private $eventDispatcher;

    /** @var  string */
    private $key;

    /** @var  array */
    private $cookieSettings;

    /** @var  bool */
    private $visitorAllowed = false;

    /** @var  Jwt\Jwt */
    private $jwt;

    /** @var  array */
    private $decodedToken;

    /**
     * Instantiate with the encoding $key, the $cookieName to which the JWT is stored, and a fully
     * configured Jwt instance that is used for decoding or as the basis for a new one hen necessary
     *
     * @param  string $key
     * @param  array $cookieSettings
     * @param  Jwt\Jwt $jwt
     */
    public function __construct($key, array $cookieSettings, Jwt\Jwt $jwt)
    {
        $this->key = $key;
        $this->cookieSettings = $cookieSettings;
        $this->jwt = $jwt;
    }

    /**
     * @param   EventDispatcher $eventDispatcher
     * @return  void
     */
    public function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /** @return  EventDispatcher */
    private function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /** @return  string */
    public function getKey()
    {
        return $this->key;
    }

    /** @return  string */
    public function getCookieName()
    {
        return $this->cookieSettings['name'];
    }

    /** @return  Jwt\Jwt */
    public function getJwt()
    {
        return $this->jwt;
    }

    /**
     * @param   Request $request
     * @return  string
     */
    private function getTokenFromRequest(Request $request)
    {
        return $request->cookies->get($this->getCookieName());
    }

    /**
     * @param   ValidationEvent $event
     * @return  void
     */
    public function validateVisitor(ValidationEvent $event)
    {
        $eventDispatcher = $this->getEventDispatcher();
        $request = $event->getRequest();

        // Check for a token, otherwise nothing to do
        $token = (array) $this->getTokenFromRequest($request);
        if (!$token) {
            return;
        }

        // Trigger the token validation
        $tokenValidationEvent = new TokenValidationEvent($token, $request);
        $eventDispatcher->dispatch(Firewall::EVENT_VALIDATE_TOKEN, $tokenValidationEvent);

        // Check the token, when it validates that should immediately allow or it should immediately prevent when
        // it gets a STATE_ILLEGAL back. The STATE_INVALID will do nothing
        if ($tokenValidationEvent->isValid()) {
            $event->setState(ValidationEvent::STATE_ALLOWED);
            $event->stopPropagation();

            // Allow hooks into the validated token by triggering an extra event
            $tokenValidatedEvent = new TokenValidatedEvent($tokenValidationEvent->getToken(), $request);
            $eventDispatcher->dispatch(Firewall::EVENT_TOKEN_VALIDATED, $tokenValidatedEvent);
        } elseif ($tokenValidationEvent->isIllegal()) {
            $event->setState(ValidationEvent::STATE_REFUSED);
            $event->setException(new ForbiddenException());
        }
    }

    /**
     * Validates the token
     *
     * @param   TokenValidationEvent $event
     * @return  void
     */
    public function validateToken(TokenValidationEvent $event)
    {
        // Decode the token and respond accordingly
        try {
            $this->decodedToken = $this->getJwt()->decode($event->getToken());
        } catch (\Exception $e) {
            // @todo add support for detecting a cookie with wrong signage to output as STATE_ILLEGAL?
            return;
        }

        // When the token was decoded and the firewall pass is set, allow through:
        if ($this->decodedToken[self::TOKEN_FIREWALL_PASS_CLAIM] === true) {
            $event->setState(TokenValidationEvent::STATE_VALIDATED);
        }
    }

    /**
     * Gets called when the visitor is allowed in, set the visitorAllowed switch to true to trigger
     * writing the Token to the response cookie
     */
    public function visitorAllowed()
    {
        $this->visitorAllowed = true;
    }

    /**
     * Launches into a EVENT_CREATE_TOKEN event with the old token, the Jwt instance necessary
     * to create a new one and the Cookie object that is added to the Response when done
     *
     * @param   SignOffEvent $event
     * @return  void
     */
    public function signOff(SignOffEvent $event)
    {
        // Only create token when visitor is allowed
        if (!$this->visitorAllowed) {
            return;
        }
        // Only renew token when it is due for it
        if (isset($this->decodedToken[self::TOKEN_RENEW_AFTER])
            && $this->decodedToken[self::TOKEN_RENEW_AFTER] > time()
        ) {
            return;
        }

        // Trigger Token creation
        $createTokenEvent = new CreateTokenEvent($this->decodedToken, $this->getJwt());
        $this->getEventDispatcher()->dispatch(Firewall::EVENT_CREATE_TOKEN, $createTokenEvent);

        // When done, create Cookie and write to Response
        $event->getResponse()->headers->setCookie(new Cookie(
            $this->getCookieName(),
                $createTokenEvent->getJwt()->encode()
        ));
    }

    /**
     * Customizes the Token, for this Guard only the TOKEN_FIREWALL_PASS_CLAIM is added
     * Other Guards can add their own by listening for the event
     *
     * @param   CreateTokenEvent $event
     * @return  void
     */
    public function createToken(CreateTokenEvent $event)
    {
        $jwt = $event->getJwt();
        $jwt->custom(true, self::TOKEN_FIREWALL_PASS_CLAIM);
    }
}