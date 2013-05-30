<?php
/**  
* Copyright (c) 2011 - 2013 Diazol
*
* Permission is hereby granted, free of charge, to any person obtaining a copy of this software 
* and associated documentation files (the "Software"), to deal in the Software without restriction, 
* including without limitation the rights to use, copy, modify, merge, publish, distribute, 
* sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is 
* furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice shall be included in all copies or substantial
* portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT 
* NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND 
* NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, 
* DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT 
* OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

  class ImportProducts {

	    private $_conversions;
		private $_languages;
		private $_log;
		
		public function __construct($sync) {
				$this->_sync = $sync;
		}
		public function import($obj) {

			if (empty($obj)) {
				return false;
			}
			
			$filename   = _PS_ROOT_DIR_.'/arte-fact/logs/import_products_'.date("Y_m_d-H-i-s").".log";
			$this->_log = fopen($filename, "a");
			$this->_getConversions();
			$this->_importProducts($obj);
			fclose($this->_log);
		}
		
		private function _setDefaultProduct() {
		
			Db::getInstance()->Execute('UPDATE IGNORE `' . _DB_PREFIX_ . 'product_attribute` SET default_on = 1');
			Db::getInstance()->Execute('UPDATE IGNORE `' . _DB_PREFIX_ . 'product_attribute_shop` SET default_on = NULL');
			Db::getInstance()->Execute('UPDATE IGNORE `' . _DB_PREFIX_ . 'product_attribute_shop` AS pas 
			                              JOIN `' . _DB_PREFIX_ . 'product_attribute` AS pa 
										    ON pas.id_product_attribute = pa.id_product_attribute AND pa.default_on = 1 
										  SET pas.default_on = 1');
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
			
		private function _importProducts($obj) {

		  if (empty($obj)) {
			return false;
		  }

		  $products = array();

		  foreach($obj as $values) {
		  
			//$data['ProduitDebutDisponibilite']  = (string)$values->ProduitDebutDisponibilite;  = not on Prestashop
			//$data['ProduitFinDisponibilite']    = (string)$values->ProduitFinDisponibilite;    = not on Prestashop
			//$data['ProduitDateCreation']        = (string)$values->ProduitDateCreation;        = not on Prestashop
			//$data['ProduitDateModification']    = (string)$values->ProduitDateModification;    = not on Prestashop
			//$data['ProduitCommentaire']         = (string)$values->ProduitCommentaire;         = not on Prestashop
			//$data['ProduitStockStatut']         = (string)$values->ProduitStockStatut;         = not on Prestashop
			
			$data['action']                     = (string)$values->attributes()->action;
			$data['ProduitReference']           = (string)$values->ProduitReference;
			$data['MarqueId']                   = (int)$values->MarqueId;
			$data['ProduitStatut']              = (int)$values->ProduitStatut;
			$data['ProduitStock']               = (int)$values->ProduitStock;
			$data['ProduitPrixHT']              = (float)(str_replace(',', '.', $values->ProduitPrixHT));
			$data['TVAId']                      = (int)$values->TVAId;
			$data['ProduitOccasion']            = (string)$values->ProduitOccasion;
			$data['ProduitPoids']               = (float)$values->ProduitPoids;
			
			foreach($values->ProduitInfos->ProduitInfo as $languages) {
			  $language                                       = strtolower((string)$languages->attributes()->lang);
			  $data['ProduitInfos'][$language]['name']        = (string)$languages->ProduitNom;
			  $data['ProduitInfos'][$language]['description'] = (string)$languages->ProduitDescription;
			}
			
			if (empty($data['ProduitInfos'])) {
			  $data['stock_update_only'] = true; 
			}
			
			$data['Categories'] = array();
			foreach($values->Categories as $categories) {
			  $data['Categories'][] = (int)$categories->CategorieId;
			}
			
			$data['ProduitImages'] = array();

			/*let's delete image only if requested... granularity describe in xml description is available in _sync array*/
			if(isset($this->_sync['product']) && ($this->_sync['product']=='tout' || $this->_sync['product']=='imagesUniquement')) {
				foreach($values->ProduitImages->ProduitImage as $images) {
				  $image = array();
				  if ($images->attributes()) {
					  $attributes      = (array)$images->attributes();
					  $image['link']   = (string)$images;
					  $image['type']   = !empty($attributes['@attributes']['type'])   ? (string)$attributes['@attributes']['type']   : '';
					  $image['nature'] = !empty($attributes['@attributes']['nature']) ? (string)$attributes['@attributes']['nature'] : '';
					  if (!empty($image['link']) && !empty($image['type'])) {
						$data['ProduitImages'][] = $image;
					  }
				  }
				}
			}

			$data['ProduitDeclinaisons'] = array();
			foreach($values->ProduitDeclinaisons->ProduitDeclinaison as $produitDeclinaison) {
			  $declinaisons = $image = array();
			  foreach($produitDeclinaison->Attributs->Attribut as $attributs) {
				$attributs_id                             = strtolower((string)$attributs->attributes()->id);
				$declinaisons['Attributs'][$attributs_id] = ucfirst(strtolower((string)$attributs));
			  }
			  $declinaisons['DeclinaisonReference']  = (string)$produitDeclinaison->DeclinaisonReference;
			  $declinaisons['DeclinaisonStock']      = (int)$produitDeclinaison->DeclinaisonStock;
			  $declinaisons['DeclinaisonPrixHT']     = (float)(str_replace(',', '.', $produitDeclinaison->DeclinaisonPrixHT));
			  $declinaisons['DeclinaisonImage']      = (string)$produitDeclinaison->DeclinaisonImage;
			  $declinaisons['ProduitPrixHT']         = (string)$data['ProduitPrixHT'];

			  // Images
			  if (!empty($produitDeclinaison->DeclinaisonImage)) {
				  $attributes          = (array)$produitDeclinaison->DeclinaisonImage->attributes();
				  $image['link']       = (string)$produitDeclinaison->DeclinaisonImage;
				  $image['type']       = !empty($attributes['@attributes']['type'])   ? (string)$attributes['@attributes']['type']   : '';
				  $image['nature']     = !empty($attributes['@attributes']['nature']) ? (string)$attributes['@attributes']['nature'] : '';
				  $image['reference']  = (string)$produitDeclinaison->DeclinaisonReference;
              }
			  if (!empty($image['link']) && !empty($image['type'])) {
			    $data['ProduitImages'][] = $image;
			  }
			  $data['ProduitDeclinaisons'][] = $declinaisons;
			}

			$data['ProduitProprietes'] = array();
			foreach($values->ProduitProprietes->ProduitPropriete as $proprietes) {
			  $language             = strtolower((string)$proprietes->attributes()->lang);
			  $propriete['lang']    = $language;
			  $propriete['nom']     = (string)$proprietes->attributes()->nom;
			  $propriete['valeur']  = (string)$proprietes->attributes()->valeur;
			  $data['ProduitProprietes'][$propriete['nom']][$propriete['lang']] = $propriete['valeur'];
			}
			unset($propriete,$language);
			$data['ProduitAccessories'] = array();
			foreach($values->ProduitComplementaires->ProduitComplementaire as $complementaire) {
			  $reference = (string)$complementaire;
			  if (!empty($reference)) {
				$data['ProduitAccessories'][] = $reference;
			  }
			}
			foreach($values->ProduitEquivalents->ProduitEquivalent as $equivalent) {
			  $reference = (string)$equivalent;
			  if (!empty($reference)) {
				$data['ProduitAccessories'][] = $reference;
			  }
			}
			
			$data['Soldes'] = array();
			foreach($values->Soldes->Solde as $solde) {
			  $data['Soldes']['value'] = (float)$solde->SoldePrix;
			  $data['Soldes']['type']  = (string)$solde->SoldePrix->attributes()->type;
			}
			$products[] = $data;
		  }

		  foreach ($products as $product) {
			if ($product['action'] == "delete") {
			  $this->_deleteProduct($product['ProduitReference']);
			  continue;
			}
			
			$this->_importProduct($product);
		  }
		}

		private function _importProduct($product) {
		
			$date = date('Y-m-d H:i:s');
			
			$brand_id       = !empty($this->_conversions['brand'][$product['MarqueId']])         ? $this->_conversions['brand'][$product['MarqueId']]         : 0;
			$category_id    = !empty($this->_conversions['category'][$product['Categories'][0]]) ? $this->_conversions['category'][$product['Categories'][0]] : 0;
			$taxes_group_id = !empty($this->_conversions['taxes_group'][$product['TVAId']])      ? $this->_conversions['taxes_group'][$product['TVAId']]      : 0;
			$tax_id         = !empty($this->_conversions['taxes'][$product['TVAId']])            ? $this->_conversions['taxes'][$product['TVAId']]            : 0;
			
			if (empty($product['ProduitReference'])) {
			  fwrite($this->_log, "\n\n ERROR: ProduitReference is empty, product can't be imported\n\n");
			  Mail::Send((int)Language::getIdByIso('fr'), 'erp-import-error', Mail::l('Import error!'), array(), strval(Configuration::get('PS_SHOP_EMAIL')), strval(Configuration::get('PS_SHOP_NAME')), strval(Configuration::get('PS_SHOP_EMAIL')), strval(Configuration::get('PS_SHOP_NAME')), NULL, NULL, dirname(__FILE__).'/../../mails/');
			  return false;
			}
			$product_id  = $this->_getProductId($product['ProduitReference']);

			$on_sale = 0;
			$price   = $product['ProduitPrixHT'];
			if (!empty($product['Soldes'])) {
			  if ($product['Soldes']['type'] == 'pourcent') {
				$price = $price - ($price * $product['Soldes']['value']);
			  } elseif($product['Soldes']['type'] == 'prixHT') {
				$price = $price - $product['Soldes']['value'];
			  } elseif($product['Soldes']['type'] == 'prixTTC') {
				$tax_rate = Db::getInstance()->getValue('SELECT rate FROM '._DB_PREFIX_.'tax WHERE id_tax = '.$tax_id);
				$price = $price - ($product['Soldes']['value']  / (($tax_rate / 100) + 1));
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			  }
			  $on_sale = 1;
			}
			
			$addTab = array(
					'id_manufacturer'     => $brand_id,
					'id_tax_rules_group'  => $taxes_group_id,
					'id_category_default' => $category_id,
					'quantity'            => pSQL($product['ProduitStock']),
					'minimal_quantity'    => 1,
					'price'               => pSQL($price),
					'reference'           => pSQL($product['ProduitReference']),
					'available_for_order' => 1,
					'show_price'          => 1,
					'on_sale'             => $on_sale,
					'active'              => 1,
					'condition'           => ($product['ProduitOccasion'] == 1 ? 'used' : 'new'),
					'weight'              => pSQL($product['ProduitPoids']),
					'date_upd'            => pSQL($date)
			);

			if ($product['ProduitStatut'] == 3) {
				$addTab['active'] = 0;
			} elseif ($product['ProduitStatut'] == 4 && empty($product['ProduitStock'])) {
				$addTab['active'] = 0;
			}
				
			if (empty($product_id))
			{

				$addTab['id_product'] = 0;
				$addTab['date_add']   = pSQL($date);
				
				Db::getInstance()->autoExecute(_DB_PREFIX_.'product', $addTab, 'INSERT');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				$product_id = Db::getInstance()->Insert_ID();
				if (empty($product_id)) {
				  return false;
				}
				
				$addTab['id_product'] = $product_id;
				$addTab['id_shop']    = 1;
				unset($addTab['id_manufacturer']);
				unset($addTab['quantity']);
				unset($addTab['reference']);
				unset($addTab['weight']);
				
				Db::getInstance()->autoExecute(_DB_PREFIX_.'product_shop', $addTab, 'INSERT');
				
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				
				fwrite($this->_log, "Updated | reference=".$product['ProduitReference']."\t|\tID=".$product_id."\n");
				
			} else {
			
			    if (!empty($product['stock_update_only'])) {
				  unset($addTab);
				  $addTab['quantity'] = pSQL($product['ProduitStock']);
				}
				
				Db::getInstance()->autoExecute(_DB_PREFIX_.'product', $addTab, 'UPDATE', "id_product = ".$product_id);
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				
				if (empty($product['stock_update_only'])) {
				  unset($addTab['id_manufacturer']);
				  unset($addTab['quantity']);
				  unset($addTab['reference']);
				  unset($addTab['weight']);
				
				  Db::getInstance()->autoExecute(_DB_PREFIX_.'product_shop', $addTab, 'UPDATE', "id_product = ".$product_id);
				  if (Db::getInstance()->getMsgError()) {
				    fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			      }
				
				  fwrite($this->_log, "Updated | reference=".$product['ProduitReference']."\t|\tID=".$product_id."\n");
				}
				
			}
			
			Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'stock_available WHERE id_product = "'.pSQL($product_id).'" AND id_product_attribute =0');
			if (Db::getInstance()->getMsgError()) {
				fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
			
			Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'stock_available 
			                              SELECT NULL, "'.pSQL($product_id).'", 0, id_shop, 0, "'.pSQL($product['ProduitStock']).'", 0, 0 FROM '._DB_PREFIX_.'shop');
			if (Db::getInstance()->getMsgError()) {
			    fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
			
			if (!empty($product['stock_update_only'])) {
			  // only stock update is available
			  return true;
			}

			foreach ($product['ProduitInfos'] as $language => $values) {
				if (empty($this->_languages[$language])) {
					continue;
				}
				if (!empty($values['name'])) {
				  $link_rewrite = strtolower(str_replace(" ", '-', trim(substr($values['name'], 0, 120))));
				  $link_rewrite = mb_convert_encoding($link_rewrite, "UTF-8");
				} else {
				  $link_rewrite = '';
				}
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'product_lang (`id_product`, `id_lang`, `name`,`description_short`, `description`, `link_rewrite`) 
											VALUES (
												"'.pSQL($product_id).'", 
												"'.pSQL($this->_languages[$language]).'",
												"'.pSQL($values['name']).'", 
												"'.pSQL($values['description']).'",
												"'.pSQL($values['description']).'",
												"'.pSQL($link_rewrite).'"
											)
											ON DUPLICATE KEY UPDATE 
											name                     = "'.pSQL($values['name']).'", 
											description_short        = "'.pSQL($values['description']).'", 
											description              = "'.pSQL($values['description']).'"');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}
			
			Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'category_product WHERE id_product = "'.pSQL($product_id).'"');
			if (Db::getInstance()->getMsgError()) {
				fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
											
			foreach ($product['Categories'] as $key => $category) {
				$category_id = !empty($this->_conversions['category'][$category]) ? $this->_conversions['category'][$category] : 0;
				
				if (empty($category_id)) {
				  continue;
				}
				
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'category_product (`id_product`, `id_category`) 
											VALUES (
												"'.pSQL($product_id).'", 
												"'.pSQL($category_id).'"
											)');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}

			if ($product['ProduitStatut'] == 1) {
				Db::getInstance()->Execute('INSERT IGNORE INTO '._DB_PREFIX_.'category_product (`id_product`, `id_category`) 
												VALUES (
													"'.pSQL($product_id).'", 
													"1"
												)');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}

			Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'accessory WHERE id_product_1 = "'.pSQL($product_id).'"');
			if (Db::getInstance()->getMsgError()) {
			  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
			foreach ($product['ProduitAccessories'] as $key => $reference) {
				$accessory_product_id = Db::getInstance()->getValue('SELECT id_product FROM '._DB_PREFIX_.'product WHERE reference = "'.$reference.'"');

				if (empty($accessory_product_id)) {
				  continue;
				}
				
				Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'accessory (`id_product_1`, `id_product_2`) 
											VALUES (
												"'.pSQL($product_id).'", 
												"'.pSQL($accessory_product_id).'"
											)');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}
			
			
			Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'feature_product WHERE id_product = '.$product_id);
			if (Db::getInstance()->getMsgError()) {
			  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
			foreach ($product['ProduitProprietes'] as $propriete_name => $proprietes) {
			
				if (empty($proprietes['fr'])) {
				  continue;
				}
				
				$id_feature = !empty($this->_conversions['feature'][$propriete_name]) ? $this->_conversions['feature'][$propriete_name] : 0;
						
				if (empty($id_feature)) {
					$addTab = array(
						'id_feature' => 0
					);
							
					Db::getInstance()->autoExecute(_DB_PREFIX_.'feature', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
					$id_feature = Db::getInstance()->Insert_ID();

					$addTab = array(
						'imports_conversions_rules_symbol'    => $propriete_name,
						'imports_conversions_rules_original'  => $id_feature,
						'imports_conversions_type'            => 'feature'
					);
					Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
					$this->_conversions['feature'][$propriete_name] = $id_feature;
						
					foreach ($this->_languages as $code => $language_id) {
						Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'feature_lang (`id_feature`, `id_lang`, `name`) 
													VALUES (
														"'.pSQL($id_feature).'", 
														"'.pSQL($language_id).'",
														"'.pSQL($propriete_name).'"
														)
													ON DUPLICATE KEY UPDATE 
														name        = "'.pSQL($propriete_name).'"'
													);
						if (Db::getInstance()->getMsgError()) {
							fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
					}
				}
				
				$id_feature_value = !empty($this->_conversions['feature_value'][$proprietes['fr']]) ? $this->_conversions['feature_value'][$proprietes['fr']] : 0;
				
				if (empty($id_feature_value)) {
					$addTab = array(
						'id_feature_value' => 0,
						'id_feature'       => $id_feature
					);
							
					Db::getInstance()->autoExecute(_DB_PREFIX_.'feature_value', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
					$id_feature_value = Db::getInstance()->Insert_ID();
							
					$addTab = array(
						'imports_conversions_rules_symbol'    => $proprietes['fr'],
						'imports_conversions_rules_original'  => $id_feature_value,
						'imports_conversions_type'            => 'feature_value'
					);
					Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
					$this->_conversions['feature_value'][$proprietes['fr']] = $id_feature_value;
					$update_feature_value_lang = false;
				} else {
					$update_feature_value_lang = true;
				}

				foreach ($proprietes as $language => $value) {
					if (empty($this->_languages[$language]) || ($language == 'fr' && $update_feature_value_lang)) {
						continue;
					}
					Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'feature_value_lang (`id_feature_value`, `id_lang`, `value`) 
												VALUES (
													"'.pSQL($id_feature_value).'", 
													"'.pSQL($this->_languages[$language]).'",
													"'.pSQL($value).'"
													)
												ON DUPLICATE KEY UPDATE 
													value        = "'.pSQL($proprietes['fr']).'"'
												);
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
				}

				$addTab = array(
					'id_product'       => $product_id,
					'id_feature_value' => $id_feature_value,
					'id_feature'       => $id_feature
				);

				Db::getInstance()->autoExecute(_DB_PREFIX_.'feature_product', $addTab, 'INSERT');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				$id_feature_value = Db::getInstance()->Insert_ID();
				
				
			}

			$this->_importProduitDeclinaisons($product['ProduitDeclinaisons'], $product_id);

			$imagesTypes = ImageType::getImagesTypes('products');
			$product_obj = new Product($product_id);

			/*let's delete image only if requested... granularity describe in xml description is available in _sync array*/
			if(isset($this->_sync['product']) && ($this->_sync['product']=='tout' || $this->_sync['product']=='imagesUniquement')) {
				fwrite($this->_log, "\n\nWARNING: deleting images for product ID = ".$product_id."\n\n");
				$status = $product_obj->deleteImages();
				if (!$status) {
					fwrite($this->_log, "\n\nERROR: can't remove image(s) to product ID = ".$product_id."\n\n");
				}
			}

			foreach($product['ProduitImages'] as $k => $image) {

				if ($image['nature']  == 'image') {
					$image_path     = dirname(__FILE__)."/../../../../arte-fact/inputs/images/produits/".$image['link'];
					if (file_exists($image_path)) {
						$imageNew = new Image();
						$imageNew->id_product = (int)($product_id);
						$imageNew->position   = Image::getHighestPosition($product_obj->id) + 1;
						if (Image::getHighestPosition($product_obj->id) == 0) {
							$imageNew->cover      =  1;
						}
						
						// A new id is generated for image when calling add()
						if ($imageNew->add())
						{
							$new_image_path = _PS_PROD_IMG_DIR_.$product_id.'-'.$imageNew->id.'.jpg';

							if (file_exists($new_image_path)) {
							  @unlink($new_image_path);
							}
							$new_path = $imageNew->getPathForCreation();
							if (copy($image_path, $new_image_path)) {
								$imagesTypes = ImageType::getImagesTypes('products');
								foreach ($imagesTypes AS $k => $imageType) {
									imageResize($new_image_path, $new_path.'-'.$imageType['name'].'.jpg', (int)($imageType['width']), (int)($imageType['height']));
								}
								if (!empty($image['reference'])) {
								  $declinations_images[$image['reference']] = $imageNew->id;
								}
							}
						}
					}
				}
			}

			if (!empty($declinations_images)) {
			  foreach ($declinations_images as $key => $value) {
				$id_product_attribute = Db::getInstance()->getValue('SELECT id_product_attribute FROM '._DB_PREFIX_.'product_attribute WHERE reference = "'.pSQL($key).'"');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				if (empty($id_product_attribute)) {
				  continue;
				}
				Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'product_attribute_image 
				                             WHERE id_product_attribute = '.$id_product_attribute);
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}

				$addTab = array(
					'id_product_attribute' => $id_product_attribute,
					'id_image'             => $value
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute_image', $addTab, 'INSERT');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				
				
			  }
			}
			
		}
		
		private function _importProduitDeclinaisons($declinaisons_array, $product_id) {
			
			if (empty($declinaisons_array) || empty($product_id)) {
				return false;
			}

			if (empty($declinaisons_array[0]['deltaStock'])) {
				Db::getInstance()->Execute('UPDATE '._DB_PREFIX_.'product_attribute SET supplier_reference = "should_be_removed" WHERE id_product = '.$product_id);
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}

		    /// TODO :: Removing attributes trash
			foreach ($declinaisons_array as $key => $declinaisons) {
				$id_product_attribute = Db::getInstance()->getValue('SELECT id_product_attribute FROM '._DB_PREFIX_.'product_attribute WHERE reference = "'.pSQL($declinaisons['DeclinaisonReference']).'"');
                if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}

				if (!empty($declinaisons['deltaStock']) && !empty($id_product_attribute)) {
					
					$addTab = array(
						'quantity' => $declinaisons['DeclinaisonStock']
					);
						
					Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute', $addTab, 'UPDATE', "id_product_attribute = ".$id_product_attribute);
					if (Db::getInstance()->getMsgError()) {
				 		fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}

					/*
					Db::getInstance()->Execute('UPDATE `'._DB_PREFIX_.'stock_available` 
												SET `quantity`='. pSQL($declinaisons['DeclinaisonStock']) .' 
												WHERE `id_product_attribute`='.$id_product_attribute.' AND `id_product` = '.$product_id.'');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}*/
					
				
				} elseif (!empty($id_product_attribute)) {
					$addTab = array(
						'quantity'           => $declinaisons['DeclinaisonStock'],
						'price'              => $declinaisons['DeclinaisonPrixHT'] - $declinaisons['ProduitPrixHT'],
						'supplier_reference' => ''
					);
					Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute', $addTab, 'UPDATE', "id_product_attribute = ".$id_product_attribute);
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						echo "erreur";
					}
                    
					$addTab['id_shop'] = 1;
					unset($addTab['quantity']);
					unset($addTab['supplier_reference']);
					
                    Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute_shop', $addTab, 'UPDATE', "id_product_attribute = ".$id_product_attribute);
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
					/////////////////////////////
					$id=Db::getInstance()->getValue('SELECT id_shop FROM '._DB_PREFIX_.'stock_available WHERE id_product_attribute = "'.$id_product_attribute.'"');
					if (Db::getInstance()->getMsgError()) {
							fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
					else if (empty($id)) {
						$addTab1 = array(
							'id_product' => $product_id,
							'id_product_attribute' => $id_product_attribute,
							'id_shop' => 1,
							'quantity'           => $declinaisons['DeclinaisonStock']
						);
						Db::getInstance()->autoExecute(_DB_PREFIX_.'stock_available', $addTab1, 'INSERT');
						if (Db::getInstance()->getMsgError()) {
							fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
						
					}
					else{
						Db::getInstance()->Execute('UPDATE `'._DB_PREFIX_.'stock_available` 
												SET `quantity`='. pSQL($declinaisons['DeclinaisonStock']) .' 
												WHERE `id_product_attribute`='.$id_product_attribute.'');
						if (Db::getInstance()->getMsgError()) {
							fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
					}
					//////////////////////////////
					
				} else {
				
					$addTab = array(
						'id_product' => $product_id,
						'reference'  => pSQL($declinaisons['DeclinaisonReference']),
						'quantity'   => pSQL($declinaisons['DeclinaisonStock']),
						'price'      => pSQL($declinaisons['DeclinaisonPrixHT'] - $declinaisons['ProduitPrixHT'])
					);
					Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}
					$id_product_attribute = Db::getInstance()->Insert_ID();
					
					$addTab['id_product_attribute'] = $id_product_attribute;
					$addTab['available_date']       = '0000-00-00';
					$addTab['id_shop'] = 1;
					unset($addTab['id_product']);
					unset($addTab['reference']);
					unset($addTab['quantity']);
					
                    Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute_shop', $addTab, 'INSERT');
					if (Db::getInstance()->getMsgError()) {
						fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
					}

					foreach ($declinaisons['Attributs'] as $attributs_group => $attributs_value) {
						$attribute_group_id = !empty($this->_conversions['attribute_group'][$attributs_group]) ? $this->_conversions['attribute_group'][$attributs_group] : 0;
						
						if (empty($attribute_group_id)) {
							$addTab = array(
								'id_attribute_group' => 0
							);
							
							Db::getInstance()->autoExecute(_DB_PREFIX_.'attribute_group', $addTab, 'INSERT');
							if (Db::getInstance()->getMsgError()) {
								fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
							}
							$attribute_group_id = Db::getInstance()->Insert_ID();
							
							$addTab = array(
								'imports_conversions_rules_symbol'    => $attributs_group,
								'imports_conversions_rules_original'  => $attribute_group_id,
								'imports_conversions_type'            => 'attribute_group'
							);
							Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
							if (Db::getInstance()->getMsgError()) {
								fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
							}
							$this->_conversions['attribute_group'][$attributs_group] = $attribute_group_id;
						
							foreach ($this->_languages as $code => $language_id) {
								Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'attribute_group_lang (`id_attribute_group`, `id_lang`, `name`, `public_name`) 
															VALUES (
																"'.pSQL($attribute_group_id).'", 
																"'.pSQL($language_id).'",
																"'.pSQL($attributs_group).'",
																"'.pSQL($attributs_group).'"
															)
															ON DUPLICATE KEY UPDATE 
																name        = "'.pSQL($attributs_group).'",
																public_name = "'.pSQL($attributs_group).'"'
															);
								if (Db::getInstance()->getMsgError()) {
									fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
								}
							}
						}

						$id_attribute = !empty($this->_conversions['attribute'][$attributs_group.'_'.$attributs_value]) ? $this->_conversions['attribute'][$attributs_group.'_'.$attributs_value] : 0;

						if (empty($id_attribute)) {
							$addTab = array(
								'id_attribute'       => 0,
								'id_attribute_group' => $attribute_group_id
							);

							Db::getInstance()->autoExecute(_DB_PREFIX_.'attribute', $addTab, 'INSERT');
							if (Db::getInstance()->getMsgError()) {
								fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
							}
							$id_attribute = Db::getInstance()->Insert_ID();

							$addTab = array(
								'imports_conversions_rules_symbol'    => $attributs_group.'_'.$attributs_value,
								'imports_conversions_rules_original'  => $id_attribute,
								'imports_conversions_type'            => 'attribute'
							);
							Db::getInstance()->autoExecute(_DB_PREFIX_.'erpartifact_conversions_rules', $addTab, 'INSERT');
							if (Db::getInstance()->getMsgError()) {
								fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
							}
							$this->_conversions['attribute'][$attributs_group.'_'.$attributs_value] = $id_attribute;
						}
						
						foreach ($this->_languages as $code => $language_id) {
							Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'attribute_lang (`id_attribute`, `id_lang`, `name`) 
															VALUES (
																"'.pSQL($id_attribute).'", 
																"'.pSQL($language_id).'",
																"'.pSQL($attributs_value).'"
															)
															ON DUPLICATE KEY UPDATE 
																name        = "'.pSQL($attributs_value).'"'
															);
							if (Db::getInstance()->getMsgError()) {
								fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
							}
						}
						
						$addTab = array(
							'id_attribute'         => $id_attribute,
							'id_product_attribute' => $id_product_attribute
						);
							
						Db::getInstance()->autoExecute(_DB_PREFIX_.'product_attribute_combination', $addTab, 'INSERT');
						if (Db::getInstance()->getMsgError()) {
							fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
						}
					}
				
				}

			}
			if (empty($declinaisons_array[0]['deltaStock'])) {
			    Db::getInstance()->Execute('DELETE FROM `'._DB_PREFIX_.'product_attribute_combination`
		                                    WHERE `id_product_attribute` IN (
											SELECT `id_product_attribute` FROM `'._DB_PREFIX_.'product_attribute` 
											WHERE supplier_reference = "should_be_removed" AND `id_product` = '.$product_id.')');
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				Db::getInstance()->Execute('DELETE FROM '._DB_PREFIX_.'product_attribute 
				                             WHERE supplier_reference = "should_be_removed" AND id_product = '.$product_id);
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
			}
		}
		
		private function _getProductId($reference) {

			$product_id  = Db::getInstance()->getValue('SELECT id_product FROM '._DB_PREFIX_.'product WHERE reference = "'.$reference.'"');
			if (Db::getInstance()->getMsgError()) {
				fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
			}
			return $product_id;

		}
		
		private function _deleteProduct($reference) {
		
		  $product_id  = $this->_getProductId($reference);
		  if (empty($product_id)) {
			return false;
		  }
		  $product_obj = new Product($product_id);
		  $product_obj->delete();
		  
		}
		
		public function importDailyStock($obj) {
			if (empty($obj)) {
				return false;
			}
			$this->_getConversions();
			$this->_processingDailyXML($obj);
		}
		
		private function _processingDailyXML($obj) {
		  if (empty($obj)) {
			return false;
		  }

		  $products = array();

		  foreach($obj as $values) {
		  
		    $data                               = array();
			$data['action']                     = (string)$values->attributes()->action;
			$data['ProduitReference']           = (string)$values->ProduitReference;
			$data['ProduitStock']               = (int)$values->ProduitStock;
			
			$data['ProduitDeclinaisons'] = array();
			foreach($values->ProduitDeclinaisons->ProduitDeclinaison as $produitDeclinaison) {
			  $declinaisons = array();
			  
			  foreach($produitDeclinaison->Attributs->Attribut as $attributs) {
				$attributs_id                             = strtolower((string)$attributs->attributes()->id);
				$declinaisons['Attributs'][$attributs_id] = (string)$attributs;
			  }
			  $declinaisons['deltaStock']            = true;
			  $declinaisons['DeclinaisonReference']  = (string)$produitDeclinaison->DeclinaisonReference;
			  $declinaisons['DeclinaisonStock']      = (string)$produitDeclinaison->DeclinaisonStock;
			  $declinaisons['DeclinaisonPrixHT']     = (string)$produitDeclinaison->DeclinaisonPrixHT;
			  $data['ProduitDeclinaisons'][] = $declinaisons;
			}
			
			$products[] = $data;
		  }

		  foreach ($products as $product) {
		  
			if ($product['action'] == "delete") {
			  $this->_deleteProduct($product['ProduitReference']);
			  continue;
			}
			
			$this->_importProductDailyUpdate($product);
		  }
		}

		private function _importProductDailyUpdate($product) {
				$product_id  = $this->_getProductId($product['ProduitReference']);
				if (empty($product_id)) {
					return false;
				}
				
				$addTab = array(
					'quantity' => pSQL($product['ProduitStock'])
				);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'product', $addTab, 'UPDATE', "id_product = ".$product_id);
				if (Db::getInstance()->getMsgError()) {
				  fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
                 
				/*
				Db::getInstance()->Execute('UPDATE `'._DB_PREFIX_.'stock_available` 
											SET `quantity`='. pSQL($product['ProduitStock']) .' 
											WHERE `id_product_attribute`=0 AND `id_product` = '.$product_id.'');
				if (Db::getInstance()->getMsgError()) {
					fwrite($this->_log, "\n\nSQL ERROR: ".Db::getInstance()->getMsgError()."\n\n");
				}
				*/
				
				$this->_importProduitDeclinaisons($product['ProduitDeclinaisons'], $product_id);

		}
	}
?>
