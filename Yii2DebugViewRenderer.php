<?php

/**
 * Yi2iDebugViewRenderer.
 *
 * @author  Roman Zhuravlev <zhuravljov@gmail.com>
 *
 * @since   1.1.13
 */
class Yii2DebugViewRenderer extends CViewRenderer
{
    /**
     * @var CViewRenderer
     */
    public $instance;

    /**
     * @var array
     */
    private $_stack = [];

    public function getStack()
    {
        return $this->_stack;
    }

    /**
     * @param CBaseController $context
     * @param string          $sourceFile
     * @param array           $data
     * @param bool            $return
     *
     * @return mixed
     */
    public function renderFile($context, $sourceFile, $data, $return)
    {
        $this->_stack[] = [
            'view' => $sourceFile,
            'data' => $data,
        ];
        if ($this->instance) {
            return $this->instance->renderFile($context, $sourceFile, $data, $return);
        }

        return $context->renderInternal($sourceFile, $data, $return);
    }

    /**
     * @param string $sourceFile
     * @param string $viewFile
     *
     * @return null|string
     */
    public function generateViewFile($sourceFile, $viewFile)
    {
        if ($this->instance) {
            return $this->instance->generateViewFile($sourceFile, $viewFile);
        }

        return null;
    }
}
