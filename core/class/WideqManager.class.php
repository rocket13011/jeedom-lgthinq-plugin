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

// include /plugins/lgthinq/core/LgLog.class.php

/*
 * Lg Smart Thinq manager for the python server
 * REST API on local http://127.0.0.1:port
 *
 */
class WideqManager {

	const WIDEQ_LAUNCHER = 'launch.sh';
  const WIDEQ_SCRIPT = 'wideqServer.py';

    /**
     * object WideqAPI.class.php
     */
  	private static $wideqApi = null;

  /**
   * répertoire du démon wideqServer.py
   */
  private static $wideqDir = null;

  public static function getWideqDir(){
    if(self::$wideqDir == null){
      $dir = dirname(__FILE__) . '/../../resources/daemon/';
      self::$wideqDir = trim(shell_exec("cd \"$dir\"; pwd")). '/';
    }
    return self::$wideqDir;
  }

  /**
   * chemin de l'alias python3.7
   * généré par l'install_apt.sh
   */
    private static $pythonBash = null;

  public static function getPython(){
    if(self::$pythonBash == null){
      // python.cmd generated by the install_apt script
      self::$pythonBash = file_get_contents( self::getWideqDir() . 'python.cmd');

      if(self::$pythonBash === false){
        // no python.cmd, check the default python version:
        $pythonVersion = $result = shell_exec(system::getCmdSudo()
         . '/usr/bin/python3 -c \'import sys; version=sys.version_info[:3]; print("{0}{1}".format(*version))\'');
        if($pythonVersion === false || $pythonVersion < 36) {
          // default python3 version too old
          LgLog::debug('no file (' . self::getWideqDir() . 'python.cmd) found, default too old : ' . $pythonVersion);
          self::$pythonBash = false;
        }else{
          self::$pythonBash = '/usr/bin/python3';
        }

      }
    }
    return self::$pythonBash;
  }

	/**
	 * infos about the python daemon
	 * check state (nok/ok) if running i.e. when the process exists
	 * add 'launchable_message' and 'log'
	 */
	public static function daemon_info() {
		$return = [];
		$state = system::ps(self::WIDEQ_SCRIPT);
		LgLog::debug('etat server wideq:' . json_encode( $state));
		$return['state'] = empty($state) ? 'nok' : 'ok';
		if(!empty($state)){
			$return['log'] = 'nb of processes='.count($state);
			if(self::$wideqApi == null){
				self::$wideqApi = lgthinq::getApi();
			}
      try {
        $ping = self::$wideqApi->ping();
        $return = array_merge( $return, $ping);
      } catch (\Exception $e) {
        LgLog::error("ping (err {$e->getCode()}): {$e->getMessage()}");
        $return['state'] = 'nok';
      }

		}

		if(count($state) > 0){
			$return = array_merge($state[0], $return);
		}
		return $return;
	}

	/**
	 * start daemon: the python flask script server
	 */
	public static function daemon_start($daemon_info = []) {

    $_debug = isset($daemon_info['debug']) && $daemon_info['debug'] ? true : false;
    $pid == isset($daemon_info['pid']) ? $daemon_info['pid'] : false;
		self::daemon_stop($pid);
		LgLog::debug("start server wideq: $_debug ___ " . json_encode( $daemon_info));
		if ($daemon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}

    $file = self::getWideqDir() . self::WIDEQ_LAUNCHER;
    // ad +x flag and run the server:
    shell_exec(system::getCmdSudo() ." chmod +x $file");
    $cmd = system::getCmdSudo() ." $file --port {$daemon_info['port']}";
		if($_debug){
			$cmd .= ' -v ';
		}
		$cmd .= ' >> ' . log::getPathToLog('lgthinq_srv') . ' 2>&1 & echo $!; ';
    $pid = exec($cmd, $output);
    $output = json_encode($output);
		LgLog::info( "Lancement démon LgThinq : $cmd => pid= {$pid} ($output)" );

		sleep(5);
		$i = 0;
		while ($i < 10) {
			try{
				$daemon_info = self::daemon_info();
				if ($daemon_info['state'] == 'ok') {
					break;
				}
			}catch(LgApiException $e){
        LgLog::debug("Waiting for daemon starting ($i)...(error {$e->getMessage()})");
			}
      LgLog::debug("Waiting for daemon starting ($i)...");

			sleep(1);
			$i++;
		}
		if ($i >= 10) {
			LgLog::error('Impossible de lancer le démon LgThinq, relancer le démon en debug et vérifiez la log', 'unableStartdaemon');
			return false;
		}
		message::removeAll('lgthinq', 'unableStartdaemon');
		LgLog::info('Démon LgThinq démarré');
    return $pid;
	}

	/**
	 * stop (kill) the python script server
	 */
	public static function daemon_stop( $pid = false) {

		try {
      if($pid !== false){
        system::kill($pid);
      }else{
        LgLog::warning('no PID; kill the '.self::WIDEQ_SCRIPT);
        system::kill(self::WIDEQ_SCRIPT);
      }

			sleep(1);
			LgLog::debug('server wideq successfully stoped!');
		} catch (\Exception $e) {
			LgLog::error( 'Stop Daemon LgThinq : ' . $e->getMessage());

		}
	}

}
