<?php

/**
 * @author  Roman Zhuravlev <zhuravljov@gmail.com>
 *
 * @since   1.1.13
 */
class Yii2RequestPanel extends Yii2DebugPanel
{
    public function getName()
    {
        return 'Request';
    }

    public function getSummary()
    {
        $data = $this->getData();

        return $this->render(\dirname(__FILE__) . '/../views/panels/request_bar.php', [
            'statusCode' => $data['statusCode'],
            'route'      => $data['route'],
            'action'     => $data['action'],
        ]);
    }

    public function getDetail()
    {
        return $this->render(\dirname(__FILE__) . '/../views/panels/request.php', [
            'data' => $this->getData(),
        ]);
    }

    public function save()
    {
        if (\function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
        } elseif (\function_exists('http_get_request_headers')) {
            $requestHeaders = http_get_request_headers();
        } else {
            $requestHeaders = [];
        }
        $responseHeaders = [];
        foreach (\headers_list() as $header) {
            if (false !== ($pos = \mb_strpos($header, ':'))) {
                $name  = \mb_substr($header, 0, $pos);
                $value = \trim(\mb_substr($header, $pos + 1));
                if (isset($responseHeaders[$name])) {
                    if (!\is_array($responseHeaders[$name])) {
                        $responseHeaders[$name] = [$responseHeaders[$name], $value];
                    } else {
                        $responseHeaders[$name][] = $value;
                    }
                } else {
                    $responseHeaders[$name] = $value;
                }
            } else {
                $responseHeaders[] = $header;
            }
        }

        $route        = Yii::app()->getUrlManager()->parseUrl(Yii::app()->getRequest());
        $action       = null;
        $actionParams = [];
        if (null !== ($ca = @Yii::app()->createController($route))) {
            /**
             * @var CController
             * @var string      $actionID
             */
            list($controller, $actionID) = $ca;
            if (!$actionID) {
                $actionID = $controller->defaultAction;
            }
            if (null !== ($a = $controller->createAction($actionID))) {
                if ($a instanceof CInlineAction) {
                    $action = \get_class($controller) . '::action' . \ucfirst($actionID) . '()';
                } else {
                    $action = \get_class($a) . '::run()';
                }
            }
            $actionParams = $controller->actionParams;
        }

        $flashes = [];
        $user    = Yii::app()->getComponent('user', false);
        if ($user instanceof CWebUser) {
            $flashes = $user->getFlashes(false);
        }

        return [
            'flashes'         => $flashes,
            'statusCode'      => $this->getStatusCode(),
            'requestHeaders'  => $requestHeaders,
            'responseHeaders' => $responseHeaders,
            'route'           => $route,
            'action'          => $action,
            'actionParams'    => $actionParams,
            'SERVER'          => empty($_SERVER) ? [] : $_SERVER,
            'GET'             => empty($_GET) ? [] : $_GET,
            'POST'            => empty($_POST) ? [] : $_POST,
            'COOKIE'          => empty($_COOKIE) ? [] : $_COOKIE,
            'FILES'           => empty($_FILES) ? [] : $_FILES,
            'SESSION'         => empty($_SESSION) ? [] : $_SESSION,
        ];
    }

    private $_statusCode;

    /**
     * @return null|int
     */
    protected function getStatusCode()
    {
        if (\function_exists('http_response_code')) {
            return \http_response_code();
        }

        return $this->_statusCode;
    }

    public function __construct($owner, $id)
    {
        parent::__construct($owner, $id);
        if (!\function_exists('http_response_code')) {
            Yii::app()->attachEventHandler('onException', [$this, 'onException']);
        }
    }

    /**
     * @param CExceptionEvent $event
     */
    protected function onException($event)
    {
        if ($event->exception instanceof CHttpException) {
            $this->_statusCode = $event->exception->statusCode;
        } else {
            $this->_statusCode = 500;
        }
    }

    /**
     * @param int $statusCode
     *
     * @return string html
     */
    public static function getStatusCodeHtml($statusCode)
    {
        $type = 'important';
        if ($statusCode >= 100 && $statusCode < 200) {
            $type = 'info';
        } elseif ($statusCode >= 200 && $statusCode < 300) {
            $type = 'success';
        } elseif ($statusCode >= 300 && $statusCode < 400) {
            $type = 'warning';
        }

        return CHtml::tag('span', ['class' => 'label label-' . $type], $statusCode);
    }
}
