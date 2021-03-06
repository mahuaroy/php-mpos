<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');


/*
 * We give access to plenty of statistics through this class
 * Statistics should be non-intrusive and not change any
 * rows in our database to ensure data integrity for the backend
 **/
class Statistics {
  private $sError = '';
  private $table = 'statistics_shares';
  private $getcache = true;

  public function __construct($debug, $mysqli, $config, $share, $user, $block, $memcache) {
    $this->debug = $debug;
    $this->mysqli = $mysqli;
    $this->share = $share;
    $this->config = $config;
    $this->user = $user;
    $this->block = $block;
    $this->memcache = $memcache;
    $this->debug->append("Instantiated Share class", 2);
  }

  /* Some basic get and set methods
   **/
  private function setErrorMessage($msg) {
    $this->sError = $msg;
  }
  public function getError() {
    return $this->sError;
  }

  // Disable fetching values from cache
  public function setGetCache($set=false) {
    $this->getcache = $set;
  }
  public function getGetCache() {
    return $this->getcache;
  }

  private function checkStmt($bState) {
    if ($bState ===! true) {
      $this->debug->append("Failed to prepare statement: " . $this->mysqli->error);
      $this->setErrorMessage('Failed to prepare statement');
      return false;
    }
    return true;
  }

  /**
   * Fetch last found blocks by time
   **/
  function getLastValidBlocksbyTime($aTimeFrame) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $aTimeFrame)) return $data;
  	$date = new DateTime();
  	$actualTime = $date->getTimestamp();
  	$aTimeDiff = $actualTime - $aTimeFrame;
  	if ($aTimeFrame == 0) $aTimeDiff = 0;
    $stmt = $this->mysqli->prepare("
      SELECT COUNT(id) AS count FROM " . $this->block->getTableName() . "
      WHERE confirmations > 0 
      AND time >= ?
      ");
      
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $aTimeDiff) && $stmt->execute() && $result = $stmt->get_result())
    	return $this->memcache->setCache(__FUNCTION__ . $aTimeFrame, $result->fetch_object()->count);
    $this->debug->append("Failed to get valid Blocks by time: ". $this->mysqli->error);
    return false;
  }
  
  

  function getLastOrphanBlocksbyTime($aTimeFrame) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $aTimeFrame)) return $data;
  	$date = new DateTime();
  	$actualTime = $date->getTimestamp();
  	$aTimeDiff = $actualTime - $aTimeFrame;
  	if ($aTimeFrame == 0) $aTimeDiff = 0;
    $stmt = $this->mysqli->prepare("
      SELECT COUNT(id) AS count FROM " . $this->block->getTableName() . "
      WHERE confirmations = -1 
      AND time >= ? 
      ");
      
    if ($this->checkStmt($stmt) && $stmt->bind_param('i', $aTimeDiff) && $stmt->execute() && $result = $stmt->get_result())
    	return $this->memcache->setCache(__FUNCTION__ . $aTimeFrame, $result->fetch_object()->count);
    $this->debug->append("Failed to get orphan Blocks by time: ". $this->mysqli->error);
    return false;
  }
  

  /**
   * Get our last $limit blocks found
   * @param limit int Last limit blocks
   * @return array
   **/
  public function getBlocksFound($limit=10) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $limit)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        b.*,
        a.username AS finder,
        a.is_anonymous AS is_anonymous,
        ROUND((difficulty * POW(2, 32 - " . $this->config['target_bits'] . ")) / POW(2, (" . $this->config['difficulty'] . " -16)), 0) AS estshares
      FROM " . $this->block->getTableName() . " AS b
      LEFT JOIN " . $this->user->getTableName() . " AS a
      ON b.account_id = a.id
      ORDER BY height DESC LIMIT ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param("i", $limit) && $stmt->execute() && $result = $stmt->get_result())
      return $this->memcache->setCache(__FUNCTION__ . $limit, $result->fetch_all(MYSQLI_ASSOC), 5);
    // Catchall
    $this->debug->append("Failed to find blocks:" . $this->mysqli->error);
    return false;
  }

  /**
   * Get our last $limit blocks found by height
   * @param limit int Last limit blocks
   * @return array
   **/
  public function getBlocksFoundHeight($iHeight=0, $limit=10) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $iHeight  . $limit)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        b.*,
        a.username AS finder,
        a.is_anonymous AS is_anonymous,
        ROUND((difficulty * POW(2, 32 - " . $this->config['target_bits'] . ")) / POW(2, (" . $this->config['difficulty'] . " -16)), 0) AS estshares
      FROM " . $this->block->getTableName() . " AS b
      LEFT JOIN " . $this->user->getTableName() . " AS a 
      ON b.account_id = a.id
      WHERE b.height <= ?
      ORDER BY height DESC LIMIT ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param("ii", $iHeight, $limit) && $stmt->execute() && $result = $stmt->get_result())
      return $this->memcache->setCache(__FUNCTION__ . $iHeight . $limit, $result->fetch_all(MYSQLI_ASSOC), 5);
    // Catchall
    $this->debug->append("Failed to find blocks:" . $this->mysqli->error);
    return false;
  }

  /**
   * Currently the only function writing to the database
   * Stored per block user statistics of valid and invalid shares
   * @param aStats array Array with user id, valid and invalid shares
   * @param iBlockId int Block ID as store in the Block table
   * @return bool
   **/
  public function updateShareStatistics($aStats, $iBlockId) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("INSERT INTO $this->table (account_id, valid, invalid, block_id) VALUES (?, ?, ?, ?)");
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $aStats['id'], $aStats['valid'], $aStats['invalid'], $iBlockId) && $stmt->execute()) return true;
    // Catchall
    $this->debug->append("Failed to update share stats: " . $this->mysqli->error);
    return false;
  }

  /**
   * update user statistics of valid and invalid pplns shares
   **/
  public function updatePPLNSShareStatistics($aStats, $iBlockId) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("
      UPDATE $this->table SET pplns_valid = ?, pplns_invalid = ? WHERE account_id = ? AND block_id = ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $aStats['valid'], $aStats['invalid'], $aStats['id'], $iBlockId) && $stmt->execute()) return true;
    // Catchall
    $this->debug->append("Failed to update pplns share stats: " . $this->mysqli->error);
    return false;
  }

  /**
   * insert user statistics of valid and invalid pplns shares "rbpplns"
   **/
  public function insertPPLNSShareStatistics($aStats, $iBlockId) {
    $this->debug->append("STA " . __METHOD__, 4);
    $stmt = $this->mysqli->prepare("INSERT INTO $this->table (account_id, valid, invalid, pplns_valid, pplns_invalid, block_id) VALUES (?, 0, 0, ?, ?, ?)");
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $aStats['id'], $aStats['valid'], $aStats['invalid'], $iBlockId) && $stmt->execute()) return true;
    // Catchall
    $this->debug->append("Failed to insert pplns share stats: " . $this->mysqli->error);
    return false;
  }

  /**
   * Fetch the share ID from stats for rbpplns
   **/
  function getIdShareStatistics($aStats, $iBlockId) {
    $stmt = $this->mysqli->prepare("
      SELECT id AS id FROM $this->table
      WHERE account_id = ? AND block_id = ?
      ");
    if ($this->checkStmt($stmt) && $stmt->bind_param('ii', $aStats['id'], $iBlockId) && $stmt->execute() && $result = $stmt->get_result())
      return $result->fetch_object()->id;
    return false;
  }

  /**
   * Get our current pool hashrate for the past 10 minutes across both
   * shares and shares_archive table
   * @param none
   * @return data object Return our hashrateas an object
   **/
  public function getCurrentHashrate($interval=600) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
      (
        (
          SELECT IFNULL(ROUND(SUM(IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty)) * POW(2, " . $this->config['target_bits'] . ") / ? / 1000), 0) AS hashrate
          FROM " . $this->share->getTableName() . "
          WHERE time > DATE_SUB(now(), INTERVAL ? SECOND)
          AND our_result = 'Y'
        ) + (
          SELECT IFNULL(ROUND(SUM(IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty)) * POW(2, " . $this->config['target_bits'] . ") / ? / 1000), 0) AS hashrate
          FROM " . $this->share->getArchiveTableName() . "
          WHERE time > DATE_SUB(now(), INTERVAL ? SECOND)
          AND our_result = 'Y'
        )
      ) AS hashrate
      FROM DUAL");
    // Catchall
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $interval, $interval, $interval, $interval) && $stmt->execute() && $result = $stmt->get_result() ) return $this->memcache->setCache(__FUNCTION__, $result->fetch_object()->hashrate);
    $this->debug->append("Failed to get hashrate: " . $this->mysqli->error);
    return false;
  }

  /**
   * Same as getCurrentHashrate but for Shares
   * @param none
   * @return data object Our share rate in shares per second
   **/
  public function getCurrentShareRate($interval=600) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
      (
        (
          SELECT ROUND(COUNT(id) / ?, 2) AS sharerate
          FROM " . $this->share->getTableName() . "
          WHERE time > DATE_SUB(now(), INTERVAL ? SECOND)
          AND our_result = 'Y'
        ) + (
          SELECT ROUND(COUNT(id) / ?, 2) AS sharerate
          FROM " . $this->share->getArchiveTableName() . "
          WHERE time > DATE_SUB(now(), INTERVAL ? SECOND)
          AND our_result = 'Y'
        )
      ) AS sharerate
      FROM DUAL");
    if ($this->checkStmt($stmt) && $stmt->bind_param('iiii', $interval, $interval, $interval, $interval) && $stmt->execute() && $result = $stmt->get_result() ) return $this->memcache->setCache(__FUNCTION__, $result->fetch_object()->sharerate);
    // Catchall
    $this->debug->append("Failed to fetch share rate: " . $this->mysqli->error);
    return false;
  }

  /**
   * Get total shares for this round, since last block found
   * @param none
   * @return data array invalid and valid shares
   **/
  public function getRoundShares() {
    $this->debug->append("STA " . __METHOD__, 4);
    // Try the statistics cron cache, then function cache, then fallback to SQL
    if ($data = $this->memcache->get(STATISTICS_ALL_USER_SHARES)) {
      $this->debug->append("Found data in statistics cache", 2);
      $total = array('valid' => 0, 'invalid' => 0);
      foreach ($data['data'] as $aUser) {
        $total['valid'] += $aUser['valid'];
        $total['invalid'] += $aUser['invalid'];
      }
      return $total;
    }
    if ($data = $this->memcache->get(STATISTICS_ROUND_SHARES)) {
      $this->debug->append("Found data in local cache", 2);
      return $data;
    }
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND(IFNULL(SUM(IF(our_result='Y', IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS valid,
        ROUND(IFNULL(SUM(IF(our_result='N', IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS invalid
      FROM " . $this->share->getTableName() . "
      WHERE UNIX_TIMESTAMP(time) > IFNULL((SELECT MAX(time) FROM " . $this->block->getTableName() . "), 0)");
    if ( $this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(STATISTICS_ROUND_SHARES, $result->fetch_assoc());
    // Catchall
    $this->debug->append("Failed to fetch round shares: " . $this->mysqli->error);
    return false;
  }

  /**
   * Get amount of shares for a all users
   * Used in statistics cron to refresh memcache data
   * @param account_id int User ID
   * @return data array invalid and valid share counts
   **/
  public function getAllUserShares() {
    $this->debug->append("STA " . __METHOD__, 4);
    if (! $data = $this->memcache->get(STATISTICS_ALL_USER_SHARES)) {
      $data['share_id'] = 0;
      $data['data'] = array();
    }
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND(IFNULL(SUM(IF(our_result='Y', IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS valid,
        ROUND(IFNULL(SUM(IF(our_result='N', IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS invalid,
        u.id AS id,
        u.donate_percent AS donate_percent,
        u.is_anonymous AS is_anonymous,
        u.username AS username
      FROM " . $this->share->getTableName() . " AS s,
           " . $this->user->getTableName() . " AS u
      WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
        AND UNIX_TIMESTAMP(s.time) > IFNULL(
          (
            SELECT MAX(b.time)
            FROM " . $this->block->getTableName() . " AS b
          ) ,0 )
        AND s.id > ?
        GROUP BY u.id");
    if ($stmt && $stmt->bind_param('i', $data['share_id']) && $stmt->execute() && $result = $stmt->get_result()) {
      $data_new = array();
      while ($row = $result->fetch_assoc()) {
        if (! array_key_exists($row['id'], $data['data'])) {
          $data['data'][$row['id']] = $row;
        } else {
          $data['data'][$row['id']]['valid'] += $row['valid'];
          $data['data'][$row['id']]['invalid'] += $row['invalid'];
          $data['data'][$row['id']]['donate_percent'] = $row['donate_percent'];
          $data['data'][$row['id']]['is_anonymous'] = $row['is_anonymous'];
        }
      }
      $data['share_id'] = $this->share->getMaxShareId();
      return $this->memcache->setCache(STATISTICS_ALL_USER_SHARES, $data);
    }
    // Catchall
    $this->debug->append("Unable to fetch all users round shares: " . $this->mysqli->error);
    return false;
  }

  /**
   * Get amount of shares for a specific user
   * @param account_id int User ID
   * @return data array invalid and valid share counts
   **/
  public function getUserShares($account_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    // Dual-caching, try statistics cron first, then fallback to local, then fallbock to SQL
    if ($data = $this->memcache->get(STATISTICS_ALL_USER_SHARES)) {
      if (array_key_exists($account_id, $data['data']))
        return $data['data'][$account_id];
      // We have no cached value, we return defaults
      return array('valid' => 0, 'invalid' => 0, 'donate_percent' => 0, 'is_anonymous' => 0);
    }
    if ($data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND(IFNULL(SUM(IF(our_result='Y', IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS valid,
        ROUND(IFNULL(SUM(IF(our_result='N', IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty), 0)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS invalid
      FROM " . $this->share->getTableName() . " AS s,
           " . $this->user->getTableName() . " AS u
      WHERE
        u.username = SUBSTRING_INDEX( s.username, '.', 1 )
        AND UNIX_TIMESTAMP(s.time) >IFNULL((SELECT MAX(b.time) FROM " . $this->block->getTableName() . " AS b),0)
        AND u.id = ?");
    if ($stmt && $stmt->bind_param("i", $account_id) && $stmt->execute() && $result = $stmt->get_result())
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $result->fetch_assoc());
    // Catchall
    $this->debug->append("Unable to fetch user round shares: " . $this->mysqli->error);
    return false;
  }

  /**
   * Admin panel specific query
   * @return data array invlid and valid shares for all accounts
   **/
  public function getAllUserStats($filter='%') {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $filter)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        a.id AS id,
        a.is_admin as is_admin,
        a.is_locked as is_locked,
        a.no_fees as no_fees,
        a.username AS username,
        a.donate_percent AS donate_percent,
        a.email AS email,
        ROUND(IFNULL(SUM(IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS shares
      FROM " . $this->user->getTableName() . " AS a
      LEFT JOIN " . $this->share->getTableName() . " AS s
      ON a.username = SUBSTRING_INDEX( s.username, '.', 1 )
      WHERE
      	a.username LIKE ?
      GROUP BY username
      ORDER BY username");
    if ($this->checkStmt($stmt) && $stmt->bind_param('s', $filter) && $stmt->execute() && $result = $stmt->get_result()) {
      return $this->memcache->setCache(__FUNCTION__ . $filter, $result->fetch_all(MYSQLI_ASSOC));
    }
  }

  /**
   * Same as getUserShares for Hashrate
   * @param account_id integer User ID
   * @return data integer Current Hashrate in khash/s
   **/
  public function getUserHashrate($account_id, $interval=600) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        (
          SELECT IFNULL(ROUND(SUM(IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty)) * POW(2, " . $this->config['target_bits'] . ") / ? / 1000), 0) AS hashrate
          FROM " . $this->share->getTableName() . " AS s,
               " . $this->user->getTableName() . " AS u
          WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
            AND our_result = 'Y'
            AND s.time > DATE_SUB(now(), INTERVAL ? SECOND)
            AND u.id = ?
        ) + (
          SELECT IFNULL(ROUND(SUM(IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty)) * POW(2, " . $this->config['target_bits'] . ") / ? / 1000), 0) AS hashrate
          FROM " . $this->share->getArchiveTableName() . " AS s,
               " . $this->user->getTableName() . " AS u
          WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
            AND our_result = 'Y'
            AND s.time > DATE_SUB(now(), INTERVAL ? SECOND)
            AND u.id = ?
        ) AS hashrate
      FROM DUAL");
    if ($this->checkStmt($stmt) && $stmt->bind_param("iiiiii", $interval, $interval, $account_id, $interval, $interval, $account_id) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $result->fetch_object()->hashrate);
    // Catchall
    $this->debug->append("Failed to fetch hashrate: " . $this->mysqli->error);
    return false;
  }

  public function getUserUnpaidPPSShares($account_id, $last_paid_pps_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        ROUND(IFNULL(SUM(IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS total
      FROM " . $this->share->getTableName() . " AS s
      JOIN " . $this->user->getTableName() . " AS a
      ON a.username = SUBSTRING_INDEX( s.username, '.', 1 )
      AND a.id = ?
      AND s.id > ?
      WHERE our_result = 'Y'");
    if ($this->checkStmt($stmt) && $stmt->bind_param("ii", $account_id, $last_paid_pps_id) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $result->fetch_object()->total);
    $this->debug->append("Failed fetching average share dificulty: " . $this->mysqli->error, 3);
    return 0;
  }

  /**
   * Get average share difficulty across all workers for user
   * @param account_id int Account ID
   * @param interval int Data interval in seconds
   * @return double Share difficulty or 0
   **/
  public function getUserShareDifficulty($account_id, $interval=600) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
        IFNULL(AVG(IF(difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), difficulty)), 0) AS avgsharediff,
        COUNT(s.id) AS total
      FROM " . $this->share->getTableName() . " AS s JOIN " . $this->user->getTableName() . " AS a
      ON a.username = SUBSTRING_INDEX( s.username, '.', 1 )
      WHERE s.time > DATE_SUB(now(), INTERVAL ? SECOND)
      AND our_result = 'Y'
      AND a.id = ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param("ii", $interval, $account_id) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $result->fetch_object()->avgsharediff);
    $this->debug->append("Failed fetching average share dificulty: " . $this->mysqli->error, 3);
    return 0;
  }

  /**
   * Same as getUserHashrate for Sharerate
   * @param account_id integer User ID
   * @return data integer Current Sharerate in shares/s
   **/
  public function getUserSharerate($account_id, $interval=600) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
      (
        (
          SELECT COUNT(s.id) / ? AS sharerate
          FROM " . $this->share->getTableName() . " AS s,
               " . $this->user->getTableName() . " AS u
          WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
            AND our_result = 'Y'
            AND s.time > DATE_SUB(now(), INTERVAL ? SECOND)
            AND u.id = ?
        ) + (
          SELECT COUNT(s.id) / ? AS sharerate
          FROM " . $this->share->getArchiveTableName() . " AS s,
               " . $this->user->getTableName() . " AS u
          WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
            AND our_result = 'Y'
            AND s.time > DATE_SUB(now(), INTERVAL ? SECOND)
            AND u.id = ?
        )
      ) AS sharerate
      FROM DUAL");
    if ($this->checkStmt($stmt) && $stmt->bind_param("iiiiii", $interval, $interval, $account_id, $interval, $interval, $account_id) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $result->fetch_object()->sharerate);
    // Catchall
    $this->debug->append("Failed to fetch sharerate: " . $this->mysqli->error);
    return false;
  }

  /**
   * Get hashrate for a specific worker
   * @param worker_id int Worker ID to fetch hashrate for
   * @return data int Current hashrate in khash/s
   **/
  public function getWorkerHashrate($worker_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $worker_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT IFNULL(ROUND(SUM(IF(difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), difficulty)) * POW(2, " . $this->config['target_bits'] . ") / 600 / 1000), 0) AS hashrate
      FROM " . $this->share->getTableName() . " AS s,
           " . $this->user->getTableName() . " AS u
      WHERE u.username = SUBSTRING_INDEX( s.username, '.', 1 )
        AND our_result = 'Y'
        AND s.time > DATE_SUB(now(), INTERVAL 600 SECOND)
        AND u.id = ?");
    if ($this->checkStmt($stmt) && $stmt->bind_param("i", $account_id) && $stmt->execute() && $result = $stmt->get_result() )
      return $this->memcache->setCache(__FUNCTION__ . $worker_id, $result->fetch_object()->hashrate);
    // Catchall
    $this->debug->append("Failed to fetch hashrate: " . $this->mysqli->error);
    return false;
  }

  /**
   * get our top contributors for either shares or hashrate
   * @param type string shares or hashes
   * @param limit int Limit result to $limit
   * @return data array Users with shares, account or hashrate, account
   **/
  public function getTopContributors($type='shares', $limit=15) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__ . $type . $limit)) return $data;
    switch ($type) {
    case 'shares':
      if ($data = $this->memcache->get(STATISTICS_ALL_USER_SHARES)) {
        // Use global cache to build data, if we have any data there
        if (!empty($data['data']) && is_array($data['data'])) {
          foreach($data['data'] as $key => $aUser) {
            $shares[$key] = $aUser['valid'];
            $username[$key] = $aUser['username'];
          }
          array_multisort($shares, SORT_DESC, $username, SORT_ASC, $data['data']);
          $count = 0;
          foreach ($data['data'] as $key => $aUser) {
            if ($count == $limit) break;
            $count++;
            $data_new[$key]['shares'] = $aUser['valid'];
            $data_new[$key]['account'] = $aUser['username'];
            $data_new[$key]['donate_percent'] = $aUser['donate_percent'];
            $data_new[$key]['is_anonymous'] = $aUser['is_anonymous'];
          }
          return $data_new;
        }
      }
      // No cached data, fallback to SQL and cache in local cache
      $stmt = $this->mysqli->prepare("
        SELECT
          a.username AS account,
          a.donate_percent AS donate_percent,
          a.is_anonymous AS is_anonymous,
          ROUND(IFNULL(SUM(IF(s.difficulty=0, POW(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)), 0) / POW(2, (" . $this->config['difficulty'] . " - 16)), 0) AS shares
        FROM " . $this->share->getTableName() . " AS s
        LEFT JOIN " . $this->user->getTableName() . " AS a
        ON SUBSTRING_INDEX( s.username, '.', 1 ) = a.username
        WHERE our_result = 'Y'
        GROUP BY account
        ORDER BY shares DESC
        LIMIT ?");
      if ($this->checkStmt($stmt) && $stmt->bind_param("i", $limit) && $stmt->execute() && $result = $stmt->get_result())
        return $this->memcache->setCache(__FUNCTION__ . $type . $limit, $result->fetch_all(MYSQLI_ASSOC));
      $this->debug->append("Fetching shares failed: ");
      return false;
      break;

    case 'hashes':
      $stmt = $this->mysqli->prepare("
         SELECT
          a.username AS account,
          a.donate_percent AS donate_percent,
          a.is_anonymous AS is_anonymous,
          IFNULL(ROUND(SUM(t1.difficulty)  * POW(2, " . $this->config['target_bits'] . ") / 600 / 1000, 2), 0) AS hashrate
        FROM
        (
          SELECT IFNULL(IF(difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), difficulty), 0) AS difficulty, username FROM " . $this->share->getTableName() . " WHERE time > DATE_SUB(now(), INTERVAL 10 MINUTE) AND our_result = 'Y'
          UNION ALL
          SELECT IFNULL(IF(difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), difficulty), 0) AS difficulty, username FROM " . $this->share->getArchiveTableName() ." WHERE time > DATE_SUB(now(), INTERVAL 10 MINUTE) AND our_result = 'Y'
        ) AS t1
        LEFT JOIN " . $this->user->getTableName() . " AS a
        ON SUBSTRING_INDEX( t1.username, '.', 1 ) = a.username
        GROUP BY account
        ORDER BY hashrate DESC LIMIT ?");
      if ($this->checkStmt($stmt) && $stmt->bind_param("i", $limit) && $stmt->execute() && $result = $stmt->get_result())
        return $this->memcache->setCache(__FUNCTION__ . $type . $limit, $result->fetch_all(MYSQLI_ASSOC));
      $this->debug->append("Fetching shares failed: " . $this->mysqli->error);
      return false;
      break;
    }
  }

  /**
   * get Hourly hashrate for a user
   * @param account_id int User ID
   * @return data array NOT FINISHED YET
   **/
  public function getHourlyHashrateByAccount($account_id) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $account_id)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
      	IFNULL(ROUND(SUM(IF(s.difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)) * POW(2, " . $this->config['target_bits'] . ") / 3600 / 1000), 0) AS hashrate,
        HOUR(s.time) AS hour
      FROM " . $this->share->getTableName() . " AS s, accounts AS a
      WHERE time < NOW() - INTERVAL 1 HOUR
        AND our_result = 'Y'
        AND time > NOW() - INTERVAL 25 HOUR
        AND a.username = SUBSTRING_INDEX( s.username, '.', 1 )
        AND a.id = ?
      GROUP BY HOUR(time)
      UNION ALL
      SELECT
        IFNULL(ROUND(SUM(IF(s.difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)) * POW(2, " . $this->config['target_bits'] . ") / 3600 / 1000), 0) AS hashrate,
        HOUR(s.time) AS hour
      FROM " . $this->share->getArchiveTableName() . " AS s, accounts AS a
      WHERE time < NOW() - INTERVAL 1 HOUR
        AND our_result = 'Y'
        AND time > NOW() - INTERVAL 25 HOUR
        AND a.username = SUBSTRING_INDEX( s.username, '.', 1 )
        AND a.id = ?
      GROUP BY HOUR(time)");
    if ($this->checkStmt($stmt) && $stmt->bind_param('ii', $account_id, $account_id) && $stmt->execute() && $result = $stmt->get_result()) {
      $iStartHour = date('G');
      // Initilize array
      for ($i = 0; $i < 24; $i++) $aData[($iStartHour + $i) % 24] = 0;
      // Fill data
      while ($row = $result->fetch_assoc()) $aData[$row['hour']] = $row['hashrate'];
      return $this->memcache->setCache(__FUNCTION__ . $account_id, $aData);
    }
    // Catchall
    $this->debug->append("Failed to fetch hourly hashrate: " . $this->mysqli->error);
    return false;
  }

  /**
   * get Hourly hashrate for the pool 
   * @param none
   * @return data array NOT FINISHED YET
   **/
  public function getHourlyHashrateByPool() {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->getGetCache() && $data = $this->memcache->get(__FUNCTION__)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT
      	IFNULL(ROUND(SUM(IF(s.difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)) * POW(2, " . $this->config['target_bits'] . ") / 3600 / 1000), 0) AS hashrate,
        HOUR(s.time) AS hour
      FROM " . $this->share->getTableName() . " AS s
      WHERE time < NOW() - INTERVAL 1 HOUR
        AND time > NOW() - INTERVAL 25 HOUR
        AND our_result = 'Y'
      GROUP BY HOUR(time)
      UNION ALL
      SELECT
        IFNULL(ROUND(SUM(IF(s.difficulty=0, pow(2, (" . $this->config['difficulty'] . " - 16)), s.difficulty)) * POW(2, " . $this->config['target_bits'] . ") / 3600 / 1000), 0) AS hashrate,
        HOUR(s.time) AS hour
      FROM " . $this->share->getArchiveTableName() . " AS s
      WHERE time < NOW() - INTERVAL 1 HOUR
        AND time > NOW() - INTERVAL 25 HOUR
        AND our_result = 'Y'
      GROUP BY HOUR(time)");
    if ($this->checkStmt($stmt) && $stmt->execute() && $result = $stmt->get_result()) {
      $iStartHour = date('G');
      // Initilize array
      for ($i = 0; $i < 24; $i++) $aData[($iStartHour + $i) % 24] = 0;
      // Fill data
      while ($row = $result->fetch_assoc()) $aData[$row['hour']] = (int) $row['hashrate'];
      return $this->memcache->setCache(__FUNCTION__, $aData);
    }
    // Catchall
    $this->debug->append("Failed to fetch hourly hashrate: " . $this->mysqli->error);
    return false;
  }

  /**
   * get user estimated payouts based on share counts
   * @param value1 mixed Round shares OR share rate
   * @param value2 mixed User shares OR share difficulty
   * @param dDonate double User donation setting
   * @param bNoFees bool User no-fees option setting
   * @return aEstimates array User estimations
   **/
  public function getUserEstimates($value1, $value2, $dDonate, $bNoFees, $ppsvalue=0) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($this->config['payout_system'] != 'pps') {
      if (@$value1['valid'] > 0  && @$value2['valid'] > 0) {
        $aEstimates['block'] = round(( (int)$value2['valid'] / (int)$value1['valid'] ) * (float)$this->config['reward'], 8);
        $bNoFees == 0 ? $aEstimates['fee'] = round(((float)$this->config['fees'] / 100) * (float)$aEstimates['block'], 8) : $aEstimates['fee'] = 0;
        $aEstimates['donation'] = round((( (float)$dDonate / 100) * ((float)$aEstimates['block'] - (float)$aEstimates['fee'])), 8);
        $aEstimates['payout'] = round((float)$aEstimates['block'] - (float)$aEstimates['donation'] - (float)$aEstimates['fee'], 8);
      } else {
        $aEstimates['block'] = 0;
        $aEstimates['fee'] = 0;
        $aEstimates['donation'] = 0;
        $aEstimates['payout'] = 0;
      }
    } else {
      // Hack so we can use this method for PPS estimates too
      // value1 = shares/s
      // value2 = avg share difficulty
      if (@$value1 > 0 && @$value2 > 0) {
        $hour = 60 * 60;
        $pps = $value1 * $value2 * $ppsvalue;
        $aEstimates['hours1'] = $pps * $hour;
        $aEstimates['hours24'] = $pps * 24 * $hour;
        $aEstimates['days7'] = $pps * 24 * 7 * $hour;
        $aEstimates['days14'] = $pps * 14 * 24 * $hour;
        $aEstimates['days30'] = $pps * 30 * 24 * $hour;
      } else {
        $aEstimates['hours1'] = 0;
        $aEstimates['hours24'] = 0;
        $aEstimates['days7'] = 0;
        $aEstimates['days14'] = 0;
        $aEstimates['days30'] = 0;
      }
    }
    return $aEstimates;
  }

  /**
   * Get pool stats last 24 hours
   * @param limit int Last number of hours
   * @return array
   **/
  public function getPoolStatsHours($hour=24) {
    $this->debug->append("STA " . __METHOD__, 4);
    if ($data = $this->memcache->get(__FUNCTION__ . $hour)) return $data;
    $stmt = $this->mysqli->prepare("
      SELECT 
      IFNULL(COUNT(id), 0) as count, 
      IFNULL(AVG(difficulty), 0) as average,
      IFNULL(ROUND(SUM((difficulty * 65536) / POW(2, (" . $this->config['difficulty'] . " -16))), 0), 0) AS expected,
      IFNULL(ROUND(SUM(shares)), 0) as shares,
      IFNULL(SUM(amount), 0) as rewards 
      FROM " . $this->block->getTableName() . "
      WHERE FROM_UNIXTIME(time) > DATE_SUB(now(), INTERVAL ? HOUR)
      AND confirmations >= 1");
    if ($this->checkStmt($stmt) && $stmt->bind_param("i", $hour) && $stmt->execute() && $result = $stmt->get_result())
      return $this->memcache->setCache(__FUNCTION__ . $hour, $result->fetch_assoc());
    // Catchall
    $this->debug->append("Failed to get pool statistics:" . $this->mysqli->error);
    return false;
  }

}


$statistics = new Statistics($debug, $mysqli, $config, $share, $user, $block, $memcache);
