<?php

  class ImportCategories {

	    private $_conversions;
		private $_languages;
		private $_default_category_id;
	
	    public function import($obj) {
			if (empty($obj)) {
				return false;
			}
			$this->_getConversions();
			$this->_getDefaultCategory();
			$this->_importCategories($obj);
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
		
		private function _getDefaultCategory() {
		  $category = Db::getInstance()->ExecuteS('SELECT id_category FROM '._DB_PREFIX_.'shop LIMIT 1');
		  if (!empty($category[0]['id_category'])) {
		    $this->_default_category_id = $category[0]['id_category'];
		  }
		  return $this->_default_category_id;
		}
		
		private function _importCategories($obj) {

		  if (empty($obj)) {
			return false;
		  }
		  
		  $categories = array();
		  
		  foreach($obj as $values) {
		  
			$data['action']             = (string)$values->attributes()->action;
			$data['id']                 = (int)$values->CategorieId;
			$data['IdParent']           = (int)$values->CategorieIdParent;
			$data['CategorieImage']     = (string)$values->CategorieImage;
			$data['CategorieOrdre']     = (int)$values->CategorieOrdre;
			$data['CategorieUrl']       = (string)$values->CategorieUrl;
			
			foreach($values->CategorieInfos->CategorieInfo as $languages) {
			
			  $language                                         = strtolower((string)$languages->attributes()->lang);
			  $data['CategorieInfos'][$language]['name']        = (string)$languages->CategorieNom;
			  $data['CategorieInfos'][$language]['description'] = (string)$languages->CategorieDescription;
			}
			$categories[] = $data;
		  }

		  foreach ($categories as $category) {
		  
			if ($category['action'] == "delete") {
			  $this->_deleteCategory($category['id']);
			  continue;
			}
			
			$this->_importCategory($category);
			
		  }

		  foreach ($categories as $category) {
			if ($category['action'] != "delete") {
				$this->_parentUpdate($category);
			}
		  }
		  Category::regenerateEntireNtree();
		  
		}
		
		private function _importCategory($category) {
		
		
			$date = date('Y-m-d H:i:s');
			$category_id = !empty($this->_conversions['category'][$category['id']]) ? $this->_conversions['category'][$category['id']] : 0;

			if (empty($category_id))
			{
				$addTab = array(
					'id_category'   => 0,
					'id_parent'     => !empty($this->_conversions['category'][$category['IdParent']]) ? $this->_conversions['category'][$category['IdParent']] : $this->_default_category_id,
					'level_depth'   => !empty($category['IdParent']) ? 2 : 1,
					'active'        => 1,
					'position'      => pSQL($category['CategorieOrdre']),
					'date_add'      => pSQL($date),
					'date_upd'      => pSQL($date)
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'category', $addTab, 'INSERT');
				$category_id = Db::getInstance()->Insert_ID();
				
				Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'category_group (`id_category`, `id_group`) 
											VALUES ('.pSQL($category_id).',1)');
				
				$addTab = array(
					'imports_conversions_rules_symbol'    => $category['id'],
					'imports_conversions_rules_original'  => $category_id,
					'imports_conversions_type'            => 'category'
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
				$this->_conversions['category'][$category['id']] = $category_id;
				
			} else {

				$addTab = array(
					'id_parent'     => !empty($this->_conversions['category'][$category['IdParent']]) ? $this->_conversions['category'][$category['IdParent']] : $this->_default_category_id,
					'level_depth'   => !empty($category['IdParent']) ? 2 : 1,
					'active'        => 1,
					'position'      => pSQL($category['CategorieOrdre']),
					'date_add'      => pSQL($date),
					'date_upd'      => pSQL($date)
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'category', $addTab, 'UPDATE', "id_category = ".$category_id);

			}
			
			Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'category_shop (`id_category`, `id_shop`, `position`) 
										 SELECT "'.pSQL($category_id).'", id_shop,1 FROM '._DB_PREFIX_.'shop');
			
			foreach ($category['CategorieInfos'] as $language => $values) {
				if (empty($this->_languages[$language])) {
					continue;
				}
				if (!empty($values['name'])) {
				  $link_rewrite = strtolower(str_replace(" ", '-', trim(substr($values['name'], 0, 120))));
				  $link_rewrite = mb_convert_encoding($link_rewrite, "UTF-8");
				} else {
				  $link_rewrite = '';
				}
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'category_lang (`id_category`, `id_lang`, `name`, `description`, `link_rewrite`) 
											VALUES (
												"'.pSQL($category_id).'", 
												"'.pSQL($this->_languages[$language]).'",
												"'.pSQL($values['name']).'", 
												"'.pSQL($values['description']).'",
												"'.pSQL($link_rewrite).'"
											)
											ON DUPLICATE KEY UPDATE 
											name        = "'.pSQL($values['name']).'", 
											description = "'.pSQL($values['description']).'"');				
			}
			
			if (!empty($category['CategorieImage'])) {
				$image_path     = dirname(__FILE__)."/../../../../arte-fact/inputs/images/arbre/".$category['CategorieImage'];
				$new_image_path = _PS_CAT_IMG_DIR_.$category_id.'.jpg';
				if (file_exists($image_path)) {
					if (file_exists($new_image_path)) {
					  @unlink($new_image_path);
					}
					if (copy($image_path, $new_image_path)) {
						$imagesTypes = ImageType::getImagesTypes('categories');
						foreach ($imagesTypes AS $k => $imageType) {
							imageResize($new_image_path, _PS_CAT_IMG_DIR_.$category_id.'-'.stripslashes($imageType['name']).'.jpg', (int)($imageType['width']), (int)($imageType['height']));
						}
					}
				}
			}
		}
		
		private function _deleteCategory($category_id) {
		
		  $category_id = !empty($this->_conversions['category'][$category_id]) ? $this->_conversions['category'][$category_id] : 0;
		  if (empty($category_id)) {
			return false;
		  } 		
		
		  $category_obj = new Category($category_id);
		  $category_obj->delete();
		  
		  // Delete conversion
		  Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'erpartifact_conversions_rules 
									  WHERE imports_conversions_type = "category" AND imports_conversions_rules_original = '.$category_id);
		  unset($this->_conversions['category'][$category_id]);

		}

		private function _parentUpdate($category) {
			$category_id = !empty($this->_conversions['category'][$category['id']]) ? $this->_conversions['category'][$category['id']] : 0;

			if (!empty($category_id))
			{
				$addTab = array(
					'id_parent'     => !empty($this->_conversions['category'][$category['IdParent']]) ? $this->_conversions['category'][$category['IdParent']] : $this->_default_category_id,
					'active'        => 1
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'category', $addTab, 'UPDATE', "id_category = ".$category_id);
			}
		}
	}
?>
