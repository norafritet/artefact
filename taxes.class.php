<?php

  class ImportTaxes {

	    private $_conversions;
		private $_languages;
	
	    public function import($obj) {
			if (empty($obj)) {
				return false;
			}
			$this->_getConversions();
			$this->_importTaxes($obj);
		}
		
		private function _getConversions() {
		
			$conversions = Db::getInstance()->ExecuteS('SELECT * FROM `'._DB_PREFIX_.'erpartifact_conversions_rules`', false, false);
			while($row = Db::getInstance()->nextRow($conversions)) {
				$this->_conversions[$row['imports_conversions_type']][$row['imports_conversions_rules_symbol']] = $row['imports_conversions_rules_original'];
			}
			  
			$languages = Db::getInstance()->ExecuteS('SELECT * FROM `'._DB_PREFIX_.'lang`', false, false);
			while($row = Db::getInstance()->nextRow($languages)) {
				$this->_languages[strtolower($row['iso_code'])] = $row['id_lang'];
			}
		
		}
		
		
		private function _importTaxes($obj) {

		  if (empty($obj)) {
			return false;
		  }

		  $taxes = array();

		  foreach($obj as $values) {
		  
			$data['action']          = (string)$values->attributes()->action;
			$data['id']              = (int)$values->TVAId;
			$data['TVADescription']  = (string)$values->TVADescription;
			$data['TVAValeur']       = ((float)$values->TVAValeur) * 100;
			$data['TVAZone']         = (string)$values->TVAValeur->attributes()->zone;
			
			$taxes[] = $data;
		  }

		  foreach ($taxes as $tax) {
		  
			if ($tax['action'] == "delete") {
			  $this->_deleteTax($tax['id']);
			  continue;
			}
			
			$this->_importTax($tax);
			
		  }
		}
		
		private function _importTax($tax) {
		
			$date = date('Y-m-d H:i:s');
			$tax_id = !empty($this->_conversions['taxes'][$tax['id']]) ? $this->_conversions['taxes'][$tax['id']] : 0;

			if (empty($tax_id))
			{
				$addTab = array(
					'id_tax' => 0,
					'active' => 1,
					'rate'   => pSQL($tax['TVAValeur'])
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'tax', $addTab, 'INSERT');
				$tax_id = Db::getInstance()->Insert_ID();
				
				$addTab = array(
					'imports_conversions_rules_symbol'    => $tax['id'],
					'imports_conversions_rules_original'  => $tax_id,
					'imports_conversions_type'            => 'taxes'
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
				$this->_conversions['taxes'][$tax['id']] = $tax_id;
				
			} else {

				$addTab = array(
					'active' => 1,
					'rate'   => pSQL($tax['TVAValeur'])
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'tax', $addTab, 'UPDATE', "id_tax = ".$tax_id);
			
			}
			
			foreach ($this->_languages as $code => $language_id) {
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'tax_lang (`id_tax`, `id_lang`, `name`) 
											VALUES (
												"'.pSQL($tax_id).'", 
												"'.pSQL($language_id).'",
												"TVA FR '.pSQL($tax['TVADescription']).'"
											)
											ON DUPLICATE KEY UPDATE 
											name        = "TVA FR '.pSQL($tax['TVADescription']).'"');
			}
			
			$tax_rules_group_id = !empty($this->_conversions['taxes_group'][$tax['id']]) ? $this->_conversions['taxes_group'][$tax['id']] : 0;

			if (empty($tax_rules_group_id))
			{
				$addTab = array(
					'id_tax_rules_group' => 0,
					'active' => 1,
					'name'   => pSQL('TVA FR '.$tax['TVADescription'])
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'tax_rules_group', $addTab, 'INSERT');
				$tax_rules_group_id = Db::getInstance()->Insert_ID();
				
				$addTab = array(
					'imports_conversions_rules_symbol'    => $tax['id'],
					'imports_conversions_rules_original'  => $tax_rules_group_id,
					'imports_conversions_type'            => 'taxes_group'
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
				$this->_conversions['taxes_group'][$tax['id']] = $tax_rules_group_id;
				
			} else {

				$addTab = array(
					'active' => 1,
					'name'   => pSQL('TVA FR '.$tax['TVADescription'])
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'tax_rules_group', $addTab, 'UPDATE', "id_tax_rules_group = ".$tax_rules_group_id);
			
			}
			
			Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'tax_rule WHERE `id_tax_rules_group` = "'.pSQL($tax_rules_group_id).'" AND id_country = "'.pSQL(Country::getByIso($tax['TVAZone'])).'"');
			
			Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'tax_rule (`id_tax_rules_group`, `id_country`, `id_state`, `id_tax`,`behavior`,`zipcode_from`, `zipcode_to`, `description`) 
											VALUES (
												"'.pSQL($tax_rules_group_id).'", 
												"'.pSQL(Country::getByIso($tax['TVAZone'])).'",
												"0",
												"'.pSQL($tax_id).'",
												"0",
												"0",
												"0",
												""
											)');
											
			Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'tax_rules_group_shop (`id_tax_rules_group`, `id_shop`) 
										 SELECT "'.pSQL($tax_rules_group_id).'", id_shop FROM '._DB_PREFIX_.'shop');
		}


		private function _deleteTax($tax_id) {

		  $tax_rules_group_id = !empty($this->_conversions['taxes_group'][$tax_id]) ? $this->_conversions['taxes_group'][$tax_id] : 0;
		  $tax_id             = !empty($this->_conversions['taxes'][$tax_id])       ? $this->_conversions['taxes'][$tax_id]       : 0;

		  if (empty($tax_id)) {
			return false;
		  }

		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'tax_lang         WHERE id_tax = '.$tax_id);
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'tax              WHERE id_tax = '.$tax_id);
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'tax_rule         WHERE id_tax = '.$tax_id);
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'tax_rules_group  WHERE id_tax_rules_group = '.$tax_rules_group_id);

		  // Delete conversion
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'erpartifact_conversions_rules 
									  WHERE imports_conversions_type = "taxes" AND imports_conversions_rules_original = '.$tax_id);
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'erpartifact_conversions_rules 
									  WHERE imports_conversions_type = "taxes_group" AND imports_conversions_rules_original = '.$tax_rules_group_id);
		  unset($this->_conversions['taxes'][$tax_id]);

		}
	}
?>
