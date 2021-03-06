<?php

namespace Webspot\Firewall\Guard;

use Webspot\Firewall\Event\ValidationEvent;
use Webspot\Firewall\Firewall;

class UserAgentGuard implements GuardInterface
{
    /** {@inheritdoc} */
    public static function getSubscribedEvents()
    {
        return [
            Firewall::EVENT_VALIDATE_REQUEST => ['validateRequest', 0],
        ];
    }

    /** @var  string[]  regexes to disallow when matched */
    private $regexes;

    public function __construct(array $regexes = [])
    {
        if (count($regexes) > 0) {
            $this->regexes = $regexes;
        }
    }

    /**
     * @param   ValidationEvent $event
     * @return  void
     */
    public function validateRequest(ValidationEvent $event)
    {
        $ua = $event->getRequest()->headers->get('User-Agent');
        foreach ($this->regexes as $regex) {
            if (preg_match($regex, $ua) > 0) {
                $event->setState(ValidationEvent::STATE_REFUSED);
                $event->setMessage('User-Agent disallowed');
                return;
            }
        }
    }
}
