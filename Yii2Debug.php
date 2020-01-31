<?php

/**
 * Application component of debug panel.
 *
 * @property string $tag
 *
 * @author  Roman Zhuravlev <zhuravljov@gmail.com>
 *
 * @since   1.1.13
 */
class Yii2Debug extends CApplicationComponent
{
    /**
     * @var array the list of IPs that are allowed to access this module.
     *            Each array element represents a single IP filter which can be either an IP address
     *            or an address with wildcard (e.g. 192.168.0.*) to represent a network segment.
     *            The default value is `['127.0.0.1', '::1']`, which means the module can only be accessed
     *            by localhost.
     */
    public $allowedIPs = ['127.0.0.1', '::1'];

    /**
     * @var null|callback|string additional php expression for access evaluation
     */
    public $accessExpression;

    /**
     * @var array|Yii2DebugPanel[] list debug panels. The array keys are the panel IDs, and values are the corresponding
     *                             panel class names or configuration arrays. This will be merged with ::corePanels().
     *                             You may reconfigure a core panel via this property by using the same panel ID.
     *                             You may also disable a core panel by setting the `enabled` property to be false.
     */
    public $panels = [];

    /**
     * @var string the directory storing the debugger data files. This can be specified using a path alias.
     */
    public $logPath;

    /**
     * @var int the maximum number of debug data files to keep. If there are more files generated,
     *          the oldest ones will be removed.
     */
    public $historySize = 50;

    /**
     * @var bool enable/disable component in application
     */
    public $enabled = true;

    /**
     * @var string module ID for viewing stored debug logs
     */
    public $moduleId = 'debug';

    /**
     * @var bool use nice route rules in debug module
     */
    public $internalUrls = true;

    /**
     * @var bool highlight code in debug logs
     */
    public $highlightCode = true;

    /**
     * @var bool show brief application configuration
     */
    public $showConfig = false;

    /**
     * @var array list of unsecure component options (like login, passwords, secret keys) that
     *            will be hidden in application configuration page
     */
    public $hiddenConfigOptions = [
        'components/db/username',
        'components/db/password',
    ];

    /**
     * @var Yii2DebugStorage
     */
    protected $storage;

    private $_tag;

    /**
     * Panel initialization.
     * Generate unique tag for page. Attach panels, log watcher. Register scripts for printing debug panel.
     */
    public function init()
    {
        parent::init();
        if (!$this->enabled) {
            return;
        }

        if (!$this->checkAccess()) {
            return;
        }

        // Do not run on console.
        if (Yii::app() instanceof CConsoleApplication) {
            return;
        }

        Yii::setPathOfAlias('yii-debug-toolbar', \dirname(__FILE__));
        Yii::app()->setImport([
            'yii-debug-toolbar.*',
            'yii-debug-toolbar.panels.*',
        ]);

        if (null === $this->logPath) {
            $this->logPath = Yii::app()->getRuntimePath() . '/debug';
        }

        $panels = [];
        foreach (CMap::mergeArray($this->corePanels(), $this->panels) as $id => $config) {
            if (isset($config['enabled']) && !$config['enabled']) {
                continue;
            }

            if (!isset($config['highlightCode'])) {
                $config['highlightCode'] = $this->highlightCode;
            }
            $panels[$id] = Yii::createComponent($config, $this, $id);
        }
        $this->panels = $panels;

        Yii::app()->setModules([
            $this->moduleId => [
                'class' => 'Yii2DebugModule',
                'owner' => $this,
            ],
        ]);

        if ($this->internalUrls && ('path' === Yii::app()->getUrlManager()->urlFormat)) {
            $rules = [];
            foreach ($this->coreUrlRules() as $key => $value) {
                $rules[$this->moduleId . '/' . $key] = $this->moduleId . '/' . $value;
            }
            Yii::app()->getUrlManager()->addRules($rules, false);
        }

        Yii::app()->attachEventHandler('onEndRequest', [$this, 'onEndRequest']);
        $this->initToolbar();
    }

    /**
     * @return string current page tag
     */
    public function getTag()
    {
        if (null === $this->_tag) {
            $this->_tag = \uniqid();
        }

        return $this->_tag;
    }

    /**
     * @return array default panels
     */
    protected function corePanels()
    {
        return [
            'config'    => [
                'class' => 'Yii2ConfigPanel',
            ],
            'request'   => [
                'class' => 'Yii2RequestPanel',
            ],
            'log'       => [
                'class' => 'Yii2LogPanel',
            ],
            'profiling' => [
                'class' => 'Yii2ProfilingPanel',
            ],
            'db'        => [
                'class' => 'Yii2DbPanel',
            ],
        ];
    }

    protected function coreUrlRules()
    {
        return [
            ''                                         => 'default/index',
            '<tag:[0-9a-f]+>/<action:toolbar|explain>' => 'default/<action>',
            '<tag:[0-9a-f]+>/<panel:\w+>'              => 'default/view',
            'latest/<panel:\w+>'                       => 'default/view',
            '<action:\w+>'                             => 'default/<action>',
        ];
    }

    /**
     * Register debug panel scripts.
     */
    protected function initToolbar()
    {
        /** @var CClientScript $cs */
        $cs = Yii::app()->getClientScript();
        $cs->registerCoreScript('jquery');
        $url = Yii::app()->createUrl($this->moduleId . '/default/toolbar', ['tag' => $this->getTag()]);
        $cs->registerScript(__CLASS__ . '#toolbar', <<<JS
    function getToolbar() {
        if (window.localStorage && localStorage.getItem('yii2-debug-toolbar') == 'minimized') {
            $('.yii2-debug-toolbar').hide();
            $('.yii2-debug-toolbar-min').show();
        } else {
            $('.yii2-debug-toolbar-min').hide();
            $('.yii2-debug-toolbar').show();
        }
        $('.yii2-debug-toolbar .yii2-debug-toolbar-toggler').click(function(){
            $('.yii2-debug-toolbar').hide();
            $('.yii2-debug-toolbar-min').show();
            if (window.localStorage) {
                localStorage.setItem('yii2-debug-toolbar', 'minimized');
            }
        });
        $('.yii2-debug-toolbar-min .yii2-debug-toolbar-toggler').click(function(){
            $('.yii2-debug-toolbar-min').hide();
            $('.yii2-debug-toolbar').show();
            if (window.localStorage) {
                localStorage.setItem('yii2-debug-toolbar', 'maximized');
            }
        });
    }
    (function($){
        if($('#yii2-debug').length === 0) {
            $('<div id="yii2-debug">').appendTo('body');
        }
        $('#yii2-debug').load('${url}', function() { getToolbar(); });
    })(jQuery);
JS
        );
    }

    /**
     * @param CEvent $event
     */
    protected function onEndRequest($event)
    {
        $this->processDebug();
    }

    /**
     * Log processing routine.
     */
    protected function processDebug()
    {
        $data = [];
        foreach ($this->panels as $panel) {
            $data[$panel->getId()] = $panel->save();
            if (isset($panel->filterData)) {
                $data[$panel->getId()] = $panel->evaluateExpression(
                    $panel->filterData,
                    ['data' => $data[$panel->getId()]]
                );
            }
            $panel->load($data[$panel->getId()]);
        }

        $data['summary'] = $this->prepareDataSummary();

        $this->getStorage()->saveTag($this->getTag(), $data);
        $this->getStorage()->addToManifest($this->getTag(), $data['summary']);
    }

    /**
     * Check access rights.
     *
     * @return bool
     */
    public function checkAccess()
    {
        if (
            null !== $this->accessExpression &&
            !$this->evaluateExpression($this->accessExpression)
        ) {
            return false;
        }
        $ip = Yii::app()->getRequest()->getUserHostAddress();
        foreach ($this->allowedIPs as $filter) {
            if (
                '*' === $filter || $filter === $ip || (
                    false !== ($pos = \mb_strpos($filter, '*')) &&
                    !\strncmp($ip, $filter, $pos)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dump variable to debug log.
     *
     * @param mixed $data
     */
    public static function dump($data)
    {
        Yii::log(\serialize($data), CLogger::LEVEL_INFO, Yii2LogPanel::CATEGORY_DUMP);
    }

    /**
     * @param string $tag
     *
     * @return bool
     */
    public function getLock($tag)
    {
        return $this->getStorage()->getLock($tag);
    }

    /**
     * @param string $tag
     * @param bool   $value
     */
    public function setLock($tag, $value)
    {
        $this->getStorage()->setLock($tag, $value);
    }

    /**
     * Convert data to plain array in recursive manner.
     *
     * @param mixed $data
     *
     * @return array
     */
    public static function prepareData($data)
    {
        static $parents = [];

        $result = [];
        if (\is_array($data) || $data instanceof CMap) {
            foreach ($data as $key => $value) {
                $result[$key] = self::prepareData($value);
            }
        } elseif (\is_object($data)) {
            if (!\in_array($data, $parents, true)) {
                \array_push($parents, $data);
                $result['class'] = \get_class($data);
                if ($data instanceof CActiveRecord) {
                    foreach ($data->attributes as $field => $value) {
                        $result[$field] = $value;
                    }
                }
                foreach (\get_object_vars($data) as $key => $value) {
                    $result[$key] = self::prepareData($value);
                }
                \array_pop($parents);
            } else {
                $result = \get_class($data) . '()';
            }
        } else {
            $result = $data;
        }

        return $result;
    }

    protected function prepareDataSummary()
    {
        $statusCode = null;
        if (isset($this->panels['request'], $this->panels['request']->data['statusCode'])) {
            $statusCode = $this->panels['request']->data['statusCode'];
        }

        $request = Yii::app()->getRequest();

        return [
            'tag'    => $this->getTag(),
            'url'    => $request->getHostInfo() . $request->getUrl(),
            'ajax'   => $request->getIsAjaxRequest(),
            'method' => $request->getRequestType(),
            'code'   => $statusCode,
            'ip'     => $request->getUserHostAddress(),
            'time'   => \time(),
        ];
    }

    public function getStorage()
    {
        if (null === $this->storage) {
            $this->storage = $this->createStorage();
        }

        return $this->storage;
    }

    /**
     * Override it in descendant.
     *
     * @return Yii2DebugStorage
     */
    protected function createStorage()
    {
        return new Yii2DebugStorage($this);
    }
}
