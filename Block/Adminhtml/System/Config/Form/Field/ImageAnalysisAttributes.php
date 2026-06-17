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
        $options = [];

        if ($attribute !== '') {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attribute)] = 'selected="selected"';
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
}
