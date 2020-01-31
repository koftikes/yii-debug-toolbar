<?php
/**
 * @var CController
 * @var string      $content
 */
Yii::app()->getClientScript()
    ->addPackage('yii-debug-toolbar', [
        'baseUrl' => CHtml::asset(Yii::getPathOfAlias('yii-debug-toolbar.assets')),
        'js'      => [
            YII_DEBUG ? 'js/bootstrap.js' : 'js/bootstrap.min.js',
            'js/filter.js',
        ],
        'css'     => [
            YII_DEBUG ? 'css/bootstrap.css' : 'css/bootstrap.min.css',
            'css/main.css',
        ],
        'depends' => ['jquery'],
    ])
    ->registerPackage('yii-debug-toolbar');
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo Yii::app()->language; ?>"
      lang="<?php echo Yii::app()->language; ?>">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title><?php echo CHtml::encode($this->pageTitle); ?></title>
</head>
<body>
<?php echo $content; ?>
</body>
</html>
