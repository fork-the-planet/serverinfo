<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\ServerInfo;

use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IDBConnection;

class DatabaseStatistics {
	protected IConfig $config;
	protected IDBConnection $connection;

	public function __construct(IConfig $config, IDBConnection $connection) {
		$this->config = $config;
		$this->connection = $connection;
	}

	/**
	 * @return array{type: string, version: string, size: string}
	 */
	public function getDatabaseStatistics(): array {
		return [
			'type' => $this->config->getSystemValueString('dbtype'),
			'version' => $this->databaseVersion(),
			'size' => $this->databaseSize(),
		];
	}

	protected function databaseVersion(): string {
		switch ($this->config->getSystemValue('dbtype')) {
			case 'sqlite':
			case 'sqlite3':
				$sql = 'SELECT sqlite_version() AS version';
				break;
			case 'oci':
				$sql = 'SELECT VERSION FROM PRODUCT_COMPONENT_VERSION';
				break;
			case 'mysql':
			case 'pgsql':
			default:
				$sql = 'SELECT VERSION() AS version';
				break;
		}
		try {
			$result = $this->connection->executeQuery($sql);
			$version = $result->fetchColumn();
			$result->closeCursor();
			if ($version) {
				return $this->cleanVersion($version);
			}
		} catch (Exception $e) {
		}
		return 'N/A';
	}

	/**
	 * Copy of phpBB's get_database_size()
	 * @link https://github.com/phpbb/phpbb/blob/release-3.1.6/phpBB/includes/functions_admin.php#L2908-L3043
	 *
	 * @copyright (c) phpBB Limited <https://www.phpbb.com>
	 * @license GNU General Public License, version 2 (GPL-2.0)
	 */
	protected function databaseSize(): string {
		$database_size = false;
		// This code is heavily influenced by a similar routine in phpMyAdmin 2.2.0
		switch ($this->config->getSystemValue('dbtype')) {
			case 'mysql':
				$mysqlEngine = ['MyISAM', 'InnoDB', 'Aria'];
				$db_name = $this->config->getSystemValue('dbname');
				$sql = 'SHOW TABLE STATUS FROM `' . $db_name . '`';
				$result = $this->connection->executeQuery($sql);
				$database_size = 0;
				while ($row = $result->fetch()) {
					if (isset($row['Engine']) && in_array($row['Engine'], $mysqlEngine)) {
						$database_size += $row['Data_length'] + $row['Index_length'];
					}
				}
				$result->closeCursor();
				break;
			case 'sqlite':
			case 'sqlite3':
				if (file_exists($this->config->getSystemValue('dbhost'))) {
					$database_size = filesize($this->config->getSystemValue('dbhost'));
				} else {
					$params = $this->connection->getInner()->getParams();
					if (file_exists($params['path'])) {
						$database_size = filesize($params['path']);
					}
				}
				break;
			case 'pgsql':
				$sql = "SELECT proname
					FROM pg_proc
					WHERE proname = 'pg_database_size'";
				$result = $this->connection->executeQuery($sql);
				$row = $result->fetch();
				$result->closeCursor();
				if ($row['proname'] === 'pg_database_size') {
					$database = $this->config->getSystemValue('dbname');
					if (strpos($database, '.') !== false) {
						[$database, ] = explode('.', $database);
					}
					$sql = "SELECT oid
						FROM pg_database
						WHERE datname = '$database'";
					$result = $this->connection->executeQuery($sql);
					$row = $result->fetch();
					$result->closeCursor();
					$oid = $row['oid'];
					$sql = 'SELECT pg_database_size(' . $oid . ') as size';
					$result = $this->connection->executeQuery($sql);
					$row = $result->fetch();
					$result->closeCursor();
					$database_size = $row['size'];
				}
				break;
			case 'oci':
				$sql = 'SELECT SUM(bytes) as dbsize
					FROM user_segments';
				$result = $this->connection->executeQuery($sql);
				$database_size = ($row = $result->fetchColumn()) ? (int)$row : false;
				$result->closeCursor();
				break;
		}
		return ($database_size !== false) ? (string)$database_size : 'N/A';
	}

	/**
	 * Try to strip away additional information
	 *
	 * @param string $version E.g. `5.6.27-0ubuntu0.14.04.1`
	 * @return string `5.6.27`
	 */
	protected function cleanVersion(string $version): string {
		$matches = [];
		preg_match('/^(\d+)(\.\d+)(\.\d+)/', $version, $matches);
		if (isset($matches[0])) {
			return $matches[0];
		}
		return $version;
	}
}
