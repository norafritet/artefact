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

  require_once('brands.class.php');
  require_once('categories.class.php');
  require_once('taxes.class.php');
  require_once('products.class.php');  
  class erpartifactImport {
    
    public function runImport() {
		set_time_limit(0);
		$exist=true;
		while($exist){
			$exist=$this->_sync();
			if($exist!==false) $exist=true;
		}
		$this->_dailyStockFeed();
	}
	
	private function _sync() {
		$sync=array();
		// let's first check what to deal with ?
		$import_dir = dirname(__FILE__)."/../../../../arte-fact/inputs/files_xml/";
		if (file_exists($import_dir)) {
			$files_dir       = scandir($import_dir);
			$exist=true;
			foreach ($files_dir as $k => $file) {
				$pos = strpos(strtolower($file), 'description');
				if ($pos !== false) {
					$desc = @new SimpleXMLElement(trim($import_dir.$file), NULL, TRUE);
					$exist=true;
					break;
				}
				else{$exist=false;}
			}
			if($exist==false){return false;}
		}
		if($desc_products=$desc->Produits) {
			if ($desc_products->attributes()) {
				$attributes      = (array)$desc_products->attributes();
				$sync['product']   = !empty($attributes['@attributes']['granularite'])   ? (string)$attributes['@attributes']['granularite']   : '';
				$debut=substr(basename($file),0,12);
				$file_xml=$file;
			}
		}
		if (file_exists($import_dir)) {
			$files_dir       = scandir($import_dir);
			foreach ($files_dir as $k => $file) {
				$pos=strpos(strtolower($file), $debut);
				if ($pos !== false && strpos(strtolower($file), 'web') !== false && strpos(strtolower($file), 'deltaStock')==false) {
					$obj = @new SimpleXMLElement(trim($import_dir.$file), NULL, TRUE);$file_current=$file;
					break;
				}	
			}
		}
		$version = $obj->attributes()->version;
		if (Configuration::get('PS_ARTIFACT_VERSION') != $version) {
			$filename   = _PS_ROOT_DIR_.'/arte-fact/logs/import_products_'.date("Y_m_d-H-i-s").".log";
			$fp_log = fopen($filename, "a");
			fwrite($fp_log, "Sorry, Import can't be run. \n You have wrong versions of application.\n");
			fwrite($fp_log, "Your version: ".Configuration::get('PS_ARTIFACT_VERSION')."\n");
			fwrite($fp_log, "Need version: ".$version."\n");
			fclose($fp_log);
			$templateVars = array();
			$templateVars['{your_version}'] = Configuration::get('PS_ARTIFACT_VERSION');
			$templateVars['{need_version}'] = $version;
			Mail::Send((int)Language::getIdByIso('fr'), 'erp-version-error', Mail::l('Import error!'), $templateVars, strval(Configuration::get('PS_SHOP_EMAIL')), strval(Configuration::get('PS_SHOP_NAME')), strval(Configuration::get('PS_SHOP_EMAIL')), strval(Configuration::get('PS_SHOP_NAME')), NULL, NULL, dirname(__FILE__).'/../../mails/');
			return false;
		}
		if (!empty($obj->Categories->Categorie)) {
			$categories_obj = new ImportCategories();
			$categories_obj->import($obj->Categories->Categorie);
		}
		if (!empty($obj->Marques->Marque)) {
			$brands_obj = new ImportBrands();
			$brands_obj->import($obj->Marques->Marque);
		}
		if (!empty($obj->TVAs->TVA)) {
			$taxes_obj = new ImportTaxes();
			$taxes_obj->import($obj->TVAs->TVA);
		}
		if (!empty($obj->Produits->Produit)) {
			$products_obj = new ImportProducts($sync);
			if(!empty($obj->Produits->Produit->ProduitStock)){
				$products_obj->_sync['product']='Uniquement'; 
			}
			if(isset($products_obj->_sync['product']) && $products_obj->_sync['product']=='stocksUniquement') {
				$products_obj->importDailyStock($obj->Produits->Produit);
			}
			else {
				$products_obj->import($obj->Produits->Produit);
			}
		}
		@unlink($import_dir.$file_xml);
		@unlink($import_dir.$file_current);
	}
	
	private function _dailyStockFeed() {
		$import_dir = dirname(__FILE__)."/../../../../arte-fact/inputs/files_xml/";
		if (!file_exists($import_dir)) {
			return false;
		}
		$dailyStockFiles = array();
		$files_dir       = scandir($import_dir);
		foreach ($files_dir as $k => $file) {
			$pos = strpos(strtolower($file), 'deltastock');
			if ($pos !== false) {
				$dailyStockFiles[filemtime($import_dir.$file)] = $file;
			}
		}
		foreach($dailyStockFiles as $time => $file) {
			$obj = @new SimpleXMLElement(trim($import_dir.$file), NULL, TRUE);
			if (!empty($obj)) {
				$products_obj = new ImportProducts('');
				$products_obj->importDailyStock($obj->Produits->Produit);
			}
			//@unlink(trim($import_dir.$file));
		}
	}
		
		
}

  
  
?>
