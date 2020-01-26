<?php
/**
 * Create search index of all resources
 *
 * @package msearch2
 * @subpackage processors
 * @property mSearch2Seo mSearch2
 */
class mseIndexCreateSeoProcessor extends mseIndexCreateProcessor {

	/**
	 * {@inheritDoc}
	 */
	public function process() {
		$this->loadClass();
		$this->mSearch2->getWorkFields();

		$collection = $this->getResources();
		if (!is_array($collection) && empty($collection)) {
			return $this->failure('mse2_err_no_resources_for_index');
		}

		$process_comments = $this->modx->getOption('mse2_index_comments', null, true, true) && class_exists('Ticket');
		$i = 0;
		/* @var modResource|Ticket|msProduct $resource */
		foreach ($collection as $data) {
			if ($data['deleted'] || !$data['searchable']) {
				$this->unIndex($data['id']);
				continue;
			}

			$class_key = $data['class_key'];
			if (!isset($this->modx->map[$class_key])) {
				continue;
			}
			$resource = $this->modx->newObject($class_key);
			$resource->fromArray($data, '', true, true);

			$comments = '';
			if ($process_comments) {
				$q = $this->modx->newQuery('TicketComment', array('deleted' => 0, 'published' => 1));
				$q->innerJoin('TicketThread', 'Thread', '`TicketComment`.`thread`=`Thread`.`id` AND `Thread`.`deleted`=0');
				$q->innerJoin('modResource', 'Resource', '`Thread`.`resource`=`Resource`.`id` AND `Resource`.`id`='.$resource->get('id'));
				$q->select('text');
				if ($q->prepare() && $q->stmt->execute()) {
					while ($row = $q->stmt->fetch(PDO::FETCH_COLUMN)) {
						$comments .= $row.' ';
					}
				}
			}
			$resource->set('comment', $comments);

			$this->Index($resource);
			$i++;
		}
		$offset = $this->_offset + $this->_limit;
		$done = $offset >= $this->_total;

		return $this->success('', array(
			'indexed' => $i,
			'offset' => $done ? 0 : $offset,
			'done' => $done,
		));
	}



	/**
	 * Create index of resource
	 *
	 * @param xPDOObject $resource
	 */
	public function Index(xPDOObject $resource) {
		$this->modx->invokeEvent('mse2OnBeforeSearchIndex', array(
			'object' => $resource,
			'resource' => $resource,
			'mSearch2' => $this->mSearch2,
		));

		$words = $intro = array();
		// For proper transliterate umlauts
		setlocale(LC_ALL, 'en_US.UTF8', LC_CTYPE);

		foreach ($this->mSearch2->fields as $field => $weight) {
			if (strpos($field, 'tv_') !== false && $resource instanceof modResource) {
				$text = $resource->getTVValue(substr($field, 3));
				// Support for arrays in TVs
				if (!empty($text) && ($text[0] == '[' || $text[0] == '{')) {
					$tmp = $this->modx->fromJSON($text);
					if (is_array($tmp)) {
						$text = $tmp;
					}
				}
			}
			else {
				$text = $resource->get($field);
			}
			if (is_array($text)) {
				$text = $this->_implode_r(' ', $text);
			}
			$text = $this->modx->stripTags($text);
			$forms = $this->_getBaseForms($text);
			$intro[] = $text;
			foreach ($forms as $form => $count) {
				$words[$form][$field] = $count;
			}
		}

		$tword = $this->modx->getTableName('mseWord');
		$tintro = $this->modx->getTableName('mseIntro');
		$resource_id = $resource->get('id');

		$intro = str_replace(array("\n","\r\n","\r"), ' ', implode(' ', $intro));
		$intro = preg_replace('#\s+#u', ' ', str_replace(array('\'','"','«','»','`'), '', $intro));
		$sql = "INSERT INTO {$tintro} (`resource`, `intro`) VALUES ('$resource_id', '$intro') ON DUPLICATE KEY UPDATE `intro` = '$intro';";
		$sql .= "DELETE FROM {$tword} WHERE `resource` = '$resource_id';";

		if (!$class_key = $resource->get('class_key')) {
			$class_key = get_class($resource);
		}
		if (!empty($words)) {
			$rows = array();
			foreach ($words as $word => $fields) {
				foreach ($fields as $field => $count) {
					$rows[] = "({$resource_id}, '{$field}', '{$word}', '{$count}', '{$class_key}')";
				}
			}
			$sql .= "INSERT INTO {$tword} (`resource`, `field`, `word`, `count`, `class_key`) VALUES " . implode(',', $rows);
		}

		$q = $this->modx->prepare($sql);
		if ($q->execute()) {
			$this->modx->invokeEvent('mse2OnSearchIndex', array(
				'object' => $resource,
				'resource' => $resource,
				'words' => $words,
				'mSearch2' => $this->mSearch2,
			));
		}
		else {
			$this->modx->log(modX::LOG_LEVEL_ERROR, '[mSearch2] Could not save search index of resource '.$resource_id.': '.print_r($q->errorInfo(),1));
		}
	}


	/**
	 * Remove index of resource
	 *
	 * @param integer $resource_id
	 */
	public function unIndex($resource_id) {
		$sql = "DELETE FROM {$this->modx->getTableName('mseWord')} WHERE `resource` = '$resource_id';";
		$sql .= "DELETE FROM {$this->modx->getTableName('mseIntro')} WHERE `resource` = '$resource_id';";

		$this->modx->exec($sql);
	}


	/**
	 * Loads mSearch2 class to processor
	 *
	 * @return bool
	 */
	public function loadClass() {
		/** @noinspection PhpUndefinedFieldInspection */
		if (!empty($this->modx->mSearch2) && $this->modx->mSearch2 instanceof mSearch2Seo) {
			/** @noinspection PhpUndefinedFieldInspection */
			$this->mSearch2 = & $this->modx->mSearch2;
		}
		else {
			if (!class_exists('mSearch2Seo')) {
                /** @noinspection PhpIncludeInspection */
                require_once MODX_CORE_PATH . 'components/msearch2/model/msearch2/msearch2seo.class.php';
			}
			$this->mSearch2 = new mSearch2Seo($this->modx, array());
		}
		$this->modx->sanitizePatterns['fenom'] = '#\{.*\}#si';

		return $this->mSearch2 instanceof mSearch2Seo;
	}

}

return 'mseIndexCreateSeoProcessor';
