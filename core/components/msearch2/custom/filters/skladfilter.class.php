<?php
class skladFilter extends mse2FiltersHandler {

	public function getTvValues(array $tvs, array $ids) {
		$filters = array();
		$q = $this->modx->newQuery('modResource', array('modResource.id:IN' => $ids));
		$q->leftJoin('modTemplateVarTemplate', 'TemplateVarTemplate',
			'TemplateVarTemplate.tmplvarid IN (SELECT id FROM ' . $this->modx->getTableName('modTemplateVar') . ' WHERE name IN ("' . implode('","', $tvs) . '") )
			AND modResource.template = TemplateVarTemplate.templateid'
		);
		$q->leftJoin('modTemplateVar', 'TemplateVar', 'TemplateVarTemplate.tmplvarid = TemplateVar.id');
		$q->leftJoin('modTemplateVarResource', 'TemplateVarResource', 'TemplateVarResource.tmplvarid = TemplateVar.id AND TemplateVarResource.contentid = modResource.id');
		$q->select('TemplateVar.name, TemplateVarResource.contentid as id, TemplateVarResource.value, TemplateVar.type, TemplateVar.default_text');
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$this->modx->queryTime += microtime(true) - $tstart;
			$this->modx->executedQueries++;
			while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
				if (empty($row['id'])) {
					continue;
				}
				elseif (is_null($row['value']) || trim($row['value']) == '') {
					$row['value'] = $row['default_text'];
				}
				if ($row['type'] == 'tag' || $row['type'] == 'autotag') {
					$row['value'] = str_replace(',', '||', $row['value']);
				}
				if ($row['type'] == 'tvSuperSelect') {
					$tmp = json_decode($row['value']);
				} else {
					$tmp = strpos($row['value'], '||') !== false ? explode('||', $row['value']) : array($row['value']);
				}
				foreach ($tmp as $v) {
					$v = str_replace('"', '"', trim($v));
					if ($v == '') {
						continue;
					}
					$name = strtolower($row['name']);
					if (isset($filters[$name][$v])) {
						$filters[$name][$v][$row['id']] = $row['id'];
					}
					else {
						$filters[$name][$v] = array($row['id'] => $row['id']);
					}
				}
			}
		}
		else {
			$this->modx->log(modX::LOG_LEVEL_ERROR, "[mSearch2] Error on get filter params.\nQuery: ".$q->toSQL()."\nResponse: ".print_r($q->stmt->errorInfo(),1));
		}

		return $filters;
	}


    /**
	 * Prepares values for filter
	 * Retrieves names of ms2 metro and replaces ids in array keys by it
	 *
	 * @param array $values
	 * @param string $name
	 *
	 * @return array Prepared values
	 */
	public function buildMetroFilter(array $values, $name = '') {
		$metros = array_keys($values);
		if (empty($metros) || (count($metros) < 2 && empty($this->config['showEmptyFilters']))) {
			return array();
		}

		$results = array();
		$this->modx->addPackage('customextra', $this->modx->getOption('core_path').'components/customextra/model/');
		$q = $this->modx->newQuery('customExtraItem', array('id:IN' => $metros));
		$q->select('id,name');
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$this->modx->queryTime += microtime(true) - $tstart;
			$this->modx->executedQueries++;
			$metros = array();
			while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
				$metros[$row['id']] = $row['name'];
			}

			foreach ($values as $metro => $ids) {
				$title = !isset($metros[$metro])
					? $this->modx->lexicon('mse2_filter_boolean_no')
					: $metros[$metro];
				$results[$title] = array(
					'title' => $title,
					'value' => $metro,
					'type' => 'vendor',
					'resources' => $ids
				);
			}
		}
		ksort($results);

		return $results;
	}
	
	public function buildCollectionsFilter(array $values, $name = '') {
	    $collections = array_keys($values);
		if (empty($collections) || (count($collections) < 2 && empty($this->config['showEmptyFilters']))) {
			return array();
		}

		$results = array();
		$this->modx->addPackage('msvendorcollections', $this->modx->getOption('core_path').'components/msvendorcollections/model/');
		$q = $this->modx->newQuery('msCollection', array('id:IN' => $collections));
		$q->select('id,name,vendor');
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$this->modx->queryTime += microtime(true) - $tstart;
			$this->modx->executedQueries++;
			$collections = array();
			while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
				$collections[$row['id']] = array('name'=>$row['name'],'vendor'=>$row['vendor']);
			}

			foreach ($values as $collection => $ids) {
				$title = !isset($collections[$collection]['name'])
					? $this->modx->lexicon('mse2_filter_boolean_no')
					: $collections[$collection]['name'];
				$vendor = !isset($collections[$collection]['vendor'])
					? 0
					: $collections[$collection]['vendor'];
				$results[$title] = array(
					'title' => $title,
					'value' => $collection,
					'type' => $vendor,
					'resources' => $ids
				);
			}
		}
		ksort($results);

		return $results;
	}
	
	/**
	 * Retrieves values from Tagger table
	 *
	 * @param array $fields
	 * @param array $ids
	 *
	 * @return array
	 */
	public function getTaggerValues(array $fields, array $ids) {
		$filters = array();
		
	    if(!$this->modx->addPackage('tagger',$this->modx->getOption('tagger.core_path',null,$this->modx->getOption('core_path').'components/tagger/').'model/')) {
	       return $filters; 
	    } 
        
		$q = $this->modx->newQuery('TaggerTagResource');
		$q->innerJoin('TaggerTag','TaggerTag','TaggerTag.id = TaggerTagResource.tag');
		$q->where(array('TaggerTagResource.resource:IN' => $ids,'TaggerTag.group:IN'=>$fields));
		$q->select(array('TaggerTagResource.resource,TaggerTag.tag,TaggerTag.group'));
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$this->modx->queryTime += microtime(true) - $tstart;
			$this->modx->executedQueries++;
			while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
				foreach ($row as $k => $v) {
					$v = str_replace('"', '&quot;', trim($v));
					
					if($k == 'tag') {
					    $k = $row['group'];
					}
					
					if ($k == 'resource' || $k == 'group') {
						continue;
					}
					elseif (isset($filters[$k][$v])) {
						$filters[$k][$v][$row['resource']] = $row['resource'];
					}
					else {
						$filters[$k][$v] = array($row['resource'] => $row['resource']);
					}
				}
			}
		}
		else {
			$this->modx->log(modX::LOG_LEVEL_ERROR, "[mSearch2] Error on get filter params.\nQuery: " . $q->toSQL() . "\nResponse: " . print_r($q->stmt->errorInfo(), 1));
		}

		return $filters;
	}
	
	/**
	 * Prepares values for filter
	 * Retrieves labels of tagger tags and replaces ids in array keys by it
	 *
	 * @param array $values
	 * @param string $name
	 *
	 * @return array Prepared values
	 */
	public function buildTaggerFilter(array $values,$name = '') {
	    if(!$this->modx->addPackage('tagger',$this->modx->getOption('tagger.core_path',null,$this->modx->getOption('core_path').'components/tagger/').'model/')) {
	       return array(); 
	    } 
         
        $tags = array_keys($values);
		if (empty($tags) || (count($tags) < 2 && empty($this->config['showEmptyFilters']))) {
			return array();
		}

		$results = array();
		$q = $this->modx->newQuery('TaggerTag', array('tag:IN' => $tags));
		$q->select('tag,label');
		$tstart = microtime(true);
		if ($q->prepare() && $q->stmt->execute()) {
			$this->modx->queryTime += microtime(true) - $tstart;
			$this->modx->executedQueries++;
			$tags = array();
			while ($row = $q->stmt->fetch(PDO::FETCH_ASSOC)) {
				$tags[$row['tag']] = $row['label'];
			}

			foreach ($values as $tag => $ids) {
				$title = !isset($tags[$tag])
					? $this->modx->lexicon('mse2_filter_boolean_no')
					: $tags[$tag];
				$results[$title] = array(
					'title' => $title,
					'value' => $tag,
					'type' => 'tagger',
					'resources' => $ids
				);
			}
		}
		ksort($results);

		return $results;
	}
	

}