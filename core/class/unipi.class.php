<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';


class unipi extends eqLogic {
  public static function health() {
    $return = array();
    $deamon_info = self::deamon_info();
    $status = true;
    foreach (eqLogic::byType('unipi') as $unipi) {
      $addr = $unipi->getConfiguration('addr');
      foreach ($deamon_info['notlaunched'] as $service) {
        if ($service == $addr) {
          $status = false;
        }
      }
    }
    $return[] = array(
      'test' => __('Service ' . $addr, __FILE__),
      'result' => ($status) ? __('OK', __FILE__) : __('NOK', __FILE__),
      'advice' => ($status) ? '' : __('Indique si le service de connexion est actif', __FILE__),
      'state' => $status,
    );
    return $return;
  }

  public static function dependancy_info() {
    $return = array();
    $return['log'] = 'unipi_dep';
    $cmd = "dpkg -l | grep python-websocket";
    exec($cmd, $output, $return_var);
    if ($output[0] != "") {
      $return['state'] = 'ok';
    } else {
      $return['state'] = 'nok';
    }
    return $return;
  }

  public static function dependancy_install() {
    exec('sudo apt-get -y install python-websocket >> ' . log::getPathToLog('unipi_dep') . ' 2>&1 &');
  }


  public static function cron5() {
    foreach (eqLogic::byType('unipi') as $unipi) {
      log::add('unipi', 'debug', 'Vérification de l\'état du Unipi : ' . $unipi->getId());
      unipi::scanDevice($unipi->getConfiguration('addr'));
    }
  }

  public static function deamon_start($_debug = false) {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }
    log::add('unipi', 'info', 'Lancement du démon unipi');

    $url = network::getNetworkAccess('internal') . '/plugins/unipi/core/api/unipi.php?apikey=' . jeedom::getApiKey('unipi');
    $service_path = realpath(dirname(__FILE__) . '/../../resources');

    foreach ($deamon_info['notlaunched'] as $addr) {
      $cmd = 'nice -n 19 python ' . $service_path . '/unipi.py ' . $addr . ' ' . $url;

      log::add('unipi', 'debug', 'Lancement démon unipi : ' . $cmd);
      $result = exec('nohup ' . $cmd . ' >> ' . log::getPathToLog('unipi_node') . ' 2>&1 &');
      if (strpos(strtolower($result), 'error') !== false || strpos(strtolower($result), 'traceback') !== false) {
        log::add('unipi', 'error', $result);
        return false;
      }
    }
    $i = 0;
    while ($i < 30) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($i >= 30) {
      log::add('unipi', 'error', 'Impossible de lancer un démon unipi, vérifiez le port', 'unableStartDeamon');
      return false;
    }
    message::removeAll('unipi', 'unableStartDeamon');
    log::add('unipi', 'info', 'Démons unipi lancé');
    return true;
  }

  public static function deamon_info() {
    $return = array();
    $return['log'] = 'unipi_node';
    $return['state'] = 'ok';
    $return['launchable'] = 'ok';
    $return['notlaunched'] = array();
    $return['launched'] = array();
    foreach (eqLogic::byType('unipi') as $unipi) {
      if ($unipi->getIsEnable() == 1 ) {
        $pid = trim( shell_exec ('ps ax | grep "unipi/resources/unipi.py '. $unipi->getConfiguration('addr') . '" | grep -v "grep" | wc -l') );
        if ($pid != '' && $pid != '0') {
          $return['launched'][] = $unipi->getConfiguration('addr');
        } else {
          $return['state'] = 'nok';
          $return['notlaunched'][] = $unipi->getConfiguration('addr');
        }
        if ($unipi->getConfiguration('addr') == '') {
          $return['launchable'] = 'nok';
          $return['launchable_message'] = __('Le port de ' . $unipi->getName() . ' n\'est pas configuré', __FILE__);
        }
      }
    }
    return $return;
  }

  public static function deamon_stop() {
    exec('kill $(ps aux | grep "unipi/resources/unipi.py" | awk \'{print $2}\')');
    log::add('unipi', 'info', 'Arrêt du service unipi');
    $deamon_info = self::deamon_info();
    if (count($deamon_info['launched']) != 0) {
      sleep(1);
      exec('kill -9 $(ps aux | grep "unipi/resources/unipi.py" | awk \'{print $2}\')');
    }
    $deamon_info = self::deamon_info();
    if (count($deamon_info['launched']) != 0) {
      sleep(1);
      exec('sudo kill -9 $(ps aux | grep "unipi/resources/unipi.py" | awk \'{print $2}\')');
    }
  }


  public function preUpdate() {
    if ($this->getConfiguration('addr') == '') {
      throw new Exception(__('L\adresse ne peut etre vide',__FILE__));
    }
    //$addr = $this->getConfiguration('addr');
    //unipi::scanDevice( $addr );
  }

  public function preSave() {
    $this->setLogicalId($this->getConfiguration('addr'));
  }

  public function postSave() {
    $addr = $this->getConfiguration('addr');
    unipi::scanDevice( $addr );
    unipi::deamon_stop($addr);
  }

  public static function scanDevice( $addr ) {
    log::add('unipi', 'info', 'Scan de Unipi addr ' . $addr);
    $elogic=self::byLogicalId($addr, 'unipi');
    $devAddr = 'http://' . $addr . '/rest/all';
    $devResult = file($devAddr);
    $devList = json_decode($devResult[0]);
    foreach($devList as $device) {
      $cmdId = $device->{'dev'} . $device->{'circuit'};
      $type = $device->{'dev'};
      $number = $device->{'circuit'};
      $name = $device->{'dev'} . ' ' . $device->{'circuit'};
      $value = $device->{'value'};
      log::add('unipi', 'debug', 'Matériel trouvé : ' . $cmdId . ' avec valeur ' . $value);
      $cmdlogic = unipiCmd::byEqLogicIdAndLogicalId($elogic->getId(),$cmdId);
      if (!is_object($cmdlogic)) {
        log::add('unipi', 'debug', 'Information non existante, création');
        //ajout dans l'ordre
        $cmds = $elogic->getCmd();
        $order = count($cmds);
        $newUnipi = new unipiCmd();
        $newUnipi->setOrder($order);
        $newUnipi->setEqLogic_id($elogic->getId());
        $newUnipi->setEqType('unipi');
        $newUnipi->setIsVisible(1);
        $newUnipi->setIsHistorized(0);
        if ($type=='input' || $type=='relay') {
          $newUnipi->setSubType('binary');
          $newUnipi->setDisplay('generic_type','ENERGY_STATE');
        } else {
          $newUnipi->setSubType('numeric');
        }
        $newUnipi->setLogicalId($cmdId);
        $newUnipi->setType('info');
        $newUnipi->setName( $name );
        $newUnipi->setConfiguration('name', $name);
        $newUnipi->setConfiguration('type', $type);
        $newUnipi->setConfiguration('number', $number);
        $newUnipi->setConfiguration('value', $value);
        $newUnipi->save();
        $newUnipi->event($value);
        $cmdId = $newUnipi->getId();
        if ($type=='ao') {
          $newUniCmd = new unipiCmd();
          $order = $order + 1;
          $cmdIdOff = $cmdId . '-set';
          $nameOff = 'Set ' . $name;
          $newUniCmd->setOrder($order);
          $newUniCmd->setEqLogic_id($elogic->getId());
          $newUniCmd->setEqType('unipi');
          $newUniCmd->setIsVisible(1);
          $newUniCmd->setLogicalId($cmdIdOff);
          $newUniCmd->setType('action');
          $newUniCmd->setSubType('slider');
          $newUniCmd->setName( $nameOff );
          $newUniCmd->setValue($cmdId);
          $newUniCmd->setConfiguration('request', '#slider#');
          $newUniCmd->setConfiguration('type', $type);
          $newUniCmd->setConfiguration('number', $number);
          $newUniCmd->save();
        }
        if ($type=='relay') {
          $newUniOff = new unipiCmd();
          $order = $order + 1;
          $cmdIdOff = $cmdId . '-off';
          $nameOff = 'Off ' . $name;
          $newUniOff->setOrder($order);
          $newUniOff->setEqLogic_id($elogic->getId());
          $newUniOff->setEqType('unipi');
          $newUniOff->setLogicalId($cmdIdOff);
          $newUniOff->setType('action');
          $newUniOff->setSubType('other');
          $newUniOff->setName( $nameOff );
          $newUniOff->setValue($cmdId);
          $newUniOff->setTemplate("dashboard","light" );
          $newUniOff->setTemplate("mobile","light" );
          $newUniOff->setConfiguration('request', '0');
          $newUniOff->setConfiguration('type', $type);
          $newUniOff->setConfiguration('number', $number);
          $newUnipi->setDisplay('generic_type','ENERGY_OFF');
          $newUniOff->save();
          $newUniOn = new unipiCmd();
          $order = $order + 1;
          $cmdIdOn = $cmdId . '-on';
          $nameOn = 'On ' . $name;
          $newUniOn->setOrder($order);
          $newUniOn->setEqLogic_id($elogic->getId());
          $newUniOn->setEqType('unipi');
          $newUniOn->setLogicalId($cmdIdOn);
          $newUniOn->setType('action');
          $newUniOn->setSubType('other');
          $newUniOn->setName( $nameOn );
          $newUniOn->setValue($cmdId);
          $newUniOn->setTemplate("dashboard","light" );
          $newUniOn->setTemplate("mobile","light" );
          $newUniOn->setConfiguration('request', '1');
          $newUniOn->setConfiguration('type', $type);
          $newUniOn->setConfiguration('number', $number);
          $newUnipi->setDisplay('generic_type','ENERGY_ON');
          $newUniOn->save();

        }

      } else {
        $cmdlogic->setConfiguration('value', $value);
        $cmdlogic->save();
        $cmdlogic->event($value);
      }
    }
  }

  public static function saveValue($cmdid, $value, $addr) {
    log::add('unipi', 'debug', 'Sauvegarde ' . $cmdid . ' à valeur ' . $value . ' sur ' . $addr);
    $elogic = self::byLogicalId($addr, 'unipi');
    if (is_object($elogic)) {
      $cmdlogic = unipiCmd::byEqLogicIdAndLogicalId($elogic->getId(),$cmdid);
      if (is_object($cmdlogic)) {
        $cmdlogic->setConfiguration('value', $value);
        $cmdlogic->save();
        $cmdlogic->event($value);
      }
    }
  }

  public static function publishUnipi( $unipi, $type, $number, $value ) {
    log::add('unipi', 'debug', 'Envoi de la commande ' . $type . $number . ' à valeur ' . $value . ' sur ' . $unipi);
    // wget -qO- htt://ip/rest/relay/3 --post-data='value=1' {"result": 1, "success": true}
    $cmdAddr = $devAddr = 'http://' . $unipi . '/rest/' . $type . '/' . $number;
    $body = 'value=' . $value;
    $opts = array('http' => array(
      'header' => "Content-Type: application/x-www-form-urlencoded\r\n".
      "Content-Length: ".strlen($body)."\r\n".
      "User-Agent:MyAgent/1.0\r\n",
      'method'  => "POST",
      'content' => $body,
    ),
  );
  $context  = stream_context_create($opts);
  $result = file_get_contents($cmdAddr, false, $context, -1, 40000);
}

public function getInfo($_infos = '') {
  $return = array();
  $return['nodeId'] = array(
    'value' => $this->getLogicalId(),
  );
  $return['lastActivity'] = array(
    'value' => $this->getConfiguration('updatetime', ''),
  );
  return $return;
}

}



class unipiCmd extends cmd {
  public function execute($_options = null) {
    switch ($this->getType()) {

      case 'info' :
      return $this->getConfiguration('value');
      break;

      case 'action' :
      $request = $this->getConfiguration('request');

      switch ($this->getSubType()) {
        case 'slider':
        $value = $_options['slider'] / 10;
        $request = str_replace('#slider#', $value, $request);
        break;
        case 'color':
        $request = str_replace('#color#', $_options['color'], $request);
        break;
        case 'message':
        if ($_options != null)  {

          $replace = array('#title#', '#message#');
          $replaceBy = array($_options['title'], $_options['message']);
          if ( $_options['title'] == '') {
            throw new Exception(__('Le sujet ne peuvent être vide', __FILE__));
          }
          $request = str_replace($replace, $replaceBy, $request);

        }
        else
        $request = 1;

        break;
        default : $request == null ?  1 : $request;

      }

      $eqLogic = $this->getEqLogic();
      $LogicalID = $this->getLogicalId();

      $type = $this->getConfiguration('type');
      $number = $this->getConfiguration('number');

      log::add('unipi', 'info', 'Envoi de ' . $type . ' num ' . $number . ' à valeur ' . $request . ' sur ' . $eqLogic->getLogicalId());

      unipi::publishUnipi(
      $eqLogic->getLogicalId() ,
      $type ,
      $number,
      $request );

      $result = $request;


      return $result;
    }

    return true;

  }
}
