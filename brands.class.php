<?php

  class ImportBrands {

	    private $_conversions;
		private $_languages;
	
	    public function import($obj) {
			if (empty($obj)) {
				return false;
			}
			$this->_getConversions();
			$this->_importBrands($obj);
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
		
		private function _importBrands($obj) {

		  if (empty($obj)) {
			return false;
		  }

		  $brands = array();

		  foreach($obj as $values) {
		  
			$data['action']          = (string)$values->attributes()->action;
			$data['id']              = (int)$values->MarqueId;
			$data['MarqueImage']     = (string)$values->MarqueImage;
			$data['MarqueUrl']       = (string)$values->MarqueUrl;
			
			foreach($values->MarqueInfos->MarqueInfo as $languages) {
			  $language                                      = strtolower((string)$languages->attributes()->lang);
			  $data['MarqueInfos'][$language]['name']        = (string)$languages->MarqueNom;
			  $data['MarqueInfos'][$language]['description'] = (string)$languages->MarqueDescription;
			  if ($language == "fr") {
				$data['MarqueName'] = (string)$languages->MarqueNom;
			  }
			}
			$brands[] = $data;
		  }

		  foreach ($brands as $brand) {
		  
			if ($brand['action'] == "delete") {
			  $this->_deleteBrand($brand['id']);
			  continue;
			}
			
			$this->_importBrand($brand);
			
		  }
		}
		
		private function _importBrand($brand) {
		
			$date = date('Y-m-d H:i:s');
			$brand_id = !empty($this->_conversions['brand'][$brand['id']]) ? $this->_conversions['brand'][$brand['id']] : 0;

			if (empty($brand_id))
			{
				$addTab = array(
					'id_manufacturer' => 0,
					'active'          => 1,
					'name'            => pSQL($brand['MarqueName']),
					'date_add'        => pSQL($date),
					'date_upd'        => pSQL($date)
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'manufacturer', $addTab, 'INSERT');
				$brand_id = Db::getInstance()->Insert_ID();
				
				$addTab = array(
					'imports_conversions_rules_symbol'    => $brand['id'],
					'imports_conversions_rules_original'  => $brand_id,
					'imports_conversions_type'            => 'brand'
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
				$this->_conversions['brand'][$brand['id']] = $brand_id;
				
			} else {

				$addTab = array(
					'active'          => 1,
					'name'            => pSQL($brand['MarqueName']),
					'date_add'        => pSQL($date),
					'date_upd'        => pSQL($date)
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'manufacturer', $addTab, 'UPDATE', "id_manufacturer = ".$brand_id);
			
			}
			
			Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'manufacturer_shop (`id_manufacturer`, `id_shop`) 
										 SELECT "'.pSQL($brand_id).'", id_shop FROM '._DB_PREFIX_.'shop');
			
			foreach ($brand['MarqueInfos'] as $language => $values) {
				if (empty($this->_languages[$language])) {
					continue;
				}
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'manufacturer_lang (`id_manufacturer`, `id_lang`, `short_description`, `description`) 
											VALUES (
												"'.pSQL($brand_id).'", 
												"'.pSQL($this->_languages[$language]).'",
												"'.pSQL($values['name']).'", 
												"'.pSQL($values['description']).'"
											)
											ON DUPLICATE KEY UPDATE 
											short_description        = "'.pSQL($values['name']).'", 
											description = "'.pSQL($values['description']).'"');
			}
			
			if (!empty($brand['MarqueImage'])) {
				$image_path     = dirname(__FILE__)."/../../../../arte-fact/inputs/images/marques/".$brand['MarqueImage'];
				$new_image_path = _PS_MANU_IMG_DIR_.$brand_id.'.jpg';
				if (file_exists($image_path)) {
					if (file_exists($new_image_path)) {
					  @unlink($new_image_path);
					}
					if (copy($image_path, $new_image_path)) {
						$imagesTypes = ImageType::getImagesTypes('manufacturers');
						foreach ($imagesTypes AS $k => $imageType) {
							imageResize($new_image_path, _PS_MANU_IMG_DIR_.$brand_id.'-'.stripslashes($imageType['name']).'.jpg', (int)($imageType['width']), (int)($imageType['height']));
						}
					}
				}
			}
		}
	
		private function _deleteBrand($brand_id) {
		
		  $brand_id = !empty($this->_conversions['brand'][$category_id]) ? $this->_conversions['brand'][$brand_id] : 0;
		  if (empty($brand_id)) {
			return false;
		  } 	
		
		
		  $brand_obj = new Manufacturer($brand_id);
		  $brand_obj->delete();
		  
		  /*
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'manufacturer_lang    WHERE id_manufacturer = '.$brand_id);
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'manufacturer         WHERE id_manufacturer = '.$brand_id);
		  */
		  
		  // Delete conversion
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'erpartifact_conversions_rules 
									  WHERE imports_conversions_type = "brand" AND imports_conversions_rules_original = '.$brand_id);
		  unset($this->_conversions['brand'][$brand_id]);

		}
	}
?>
