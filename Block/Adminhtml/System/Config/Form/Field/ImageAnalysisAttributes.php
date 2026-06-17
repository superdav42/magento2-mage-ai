<?php
/**
 * Mageprince
 *
 * @category    Mageprince
 * @package     Mageprince_MageAI
 */

namespace Mageprince\MageAI\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

class ImageAnalysisAttributes extends AbstractFieldArray
{
    /**
     * @var AttributeColumn|null
     */
    protected $attributeRenderer;

    /**
     * @var OverwritePolicyColumn|null
     */
    protected $policyRenderer;

    /**
     * @var AllowNewOptionsColumn|null
     */
    protected $allowNewOptionsRenderer;

    /**
     * Prepare dynamic row columns.
     *
     * @return void
     */
    protected function _prepareToRender()
    {
        $this->addColumn('attribute', [
            'label' => __('Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);
        $this->addColumn('instruction', [
            'label' => __('Prompt Description'),
            'class' => 'required-entry',
            'style' => 'width:420px',
        ]);
        $this->addColumn('policy', [
            'label' => __('Update Policy'),
            'renderer' => $this->getPolicyRenderer(),
        ]);
        $this->addColumn('allow_new_options', [
            'label' => __('Create Options'),
            'renderer' => $this->getAllowNewOptionsRenderer(),
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Attribute');
    }

    /**
     * Mark selected attribute in each row.
     *
     * @param DataObject $row
     * @return void
     */
    protected function _prepareArrayRow(DataObject $row)
    {
        $attribute = (string) $row->getData('attribute');
        $policy = (string) $row->getData('policy');
        $allowNewOptions = (string) $row->getData('allow_new_options');
        $options = [];

        if ($attribute !== '') {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attribute)] = 'selected="selected"';
        }
        if ($policy !== '') {
            $options['option_' . $this->getPolicyRenderer()->calcOptionHash($policy)] = 'selected="selected"';
        }
        if ($allowNewOptions !== '') {
            $options['option_' . $this->getAllowNewOptionsRenderer()->calcOptionHash($allowNewOptions)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * Get product attribute select renderer.
     *
     * @return AttributeColumn
     */
    private function getAttributeRenderer(): AttributeColumn
    {
        if ($this->attributeRenderer === null) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                AttributeColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->attributeRenderer;
    }

    /**
     * Get overwrite policy select renderer.
     *
     * @return OverwritePolicyColumn
     */
    private function getPolicyRenderer(): OverwritePolicyColumn
    {
        if ($this->policyRenderer === null) {
            $this->policyRenderer = $this->getLayout()->createBlock(
                OverwritePolicyColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->policyRenderer;
    }

    /**
     * Get allow-new-options select renderer.
     *
     * @return AllowNewOptionsColumn
     */
    private function getAllowNewOptionsRenderer(): AllowNewOptionsColumn
    {
        if ($this->allowNewOptionsRenderer === null) {
            $this->allowNewOptionsRenderer = $this->getLayout()->createBlock(
                AllowNewOptionsColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->allowNewOptionsRenderer;
    }
}
