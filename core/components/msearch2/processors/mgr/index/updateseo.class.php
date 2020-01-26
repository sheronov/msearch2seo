<?php
/**
 * Update search index of one resource
 *
 * @package msearch2
 * @subpackage processors
 */

require_once 'createseo.class.php';

class mseIndexUpdateSeoProcessor extends mseIndexCreateSeoProcessor
{

    public function process()
    {
        if (!$this->getProperty('id') && !$this->getProperty('seo_id')) {
            return $this->failure('mse2_err_resource_ns');
        }

        return parent::process();
    }


    /**
     * Prepares query before retrieving resources
     *
     * @param  xPDOQuery  $c
     *
     * @return xPDOQuery
     */
    public function prepareQuery(xPDOQuery $c)
    {
        if ($this->getProperty('id')) {
            $c->where(['id' => $this->getProperty('id')]);
        } elseif ($this->getProperty('seo_id')) {
            $c->where(['sfUrls.id' => $this->getProperty('seo_id')]);
        }


        return $c;
    }

}

return 'mseIndexUpdateSeoProcessor';