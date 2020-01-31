<?php
/**
 * @var Yii2ConfigPanel
 * @var array           $data
 */
?>
<?php echo $this->render(\dirname(__FILE__) . '/_detail.php', [
    'caption' => 'Application Configuration',
    'values'  => [
        'Yii Version'      => $data['application']['yii'],
        'Application Name' => $data['application']['name'],
        'Time Zone'        => isset($data['application']['timezone']) ? $data['application']['timezone'] : '',
        'Debug Mode'       => $data['application']['debug'] ? 'Yes' : 'No',
    ],
]); ?>
<?php if ($this->owner->showConfig): ?>
    <div>
        <?php echo CHtml::link('Configuration', ['config'], ['class' => 'btn btn-info']); ?>
    </div>
<?php endif; ?>
<?php echo $this->render(\dirname(__FILE__) . '/_detail.php', [
    'caption' => 'PHP Configuration',
    'values'  => [
        'PHP Version' => $data['php']['version'],
        'Xdebug'      => $data['php']['xdebug'] ? 'Enabled' : 'Disabled',
        'APC'         => $data['php']['apc'] ? 'Enabled' : 'Disabled',
        'Memcache'    => $data['php']['memcache'] ? 'Enabled' : 'Disabled',
    ],
]); ?>
<div>
    <?php echo CHtml::link('phpinfo()', ['phpinfo'], ['class' => 'btn btn-info']); ?>
</div>
