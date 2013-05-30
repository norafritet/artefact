<?php

  class Semaphore {
    private $lock_file;
    
    private $_fp;
    
    public function __construct() {
      $this->lock_file = dirname(__FILE__) . '/../../../../arte-fact/inputs/ArtE_fact.lock';
    }

    public function is_active() {
      
      $result = file_exists($this->lock_file);
      
      if ($result) {
        $fp = fopen($this->lock_file, 'w+');
        if (flock($fp, LOCK_EX | LOCK_NB)) {
          $result = FALSE;
        }
      }
      return $result;
    }
    
    public function active() {
      $this->_fp = fopen($this->lock_file, 'w+');
      flock($this->_fp, LOCK_EX);
      fwrite($this->_fp, getmypid());
    }
    
    public function deactive() {
      fclose($this->_fp);
      if (file_exists($this->lock_file)) {
        unlink($this->lock_file);  
      }
    }
  }
?>
