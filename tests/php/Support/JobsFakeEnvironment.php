<?php
/**
 * Shop and server facts the test decides.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;

final class JobsFakeEnvironment implements ShopEnvironment {

	public string $woo        = '9.4.0';
	public string $wp         = '6.4.3';
	public string $php        = '8.3.6';
	public string $db         = 'MariaDB 10.11';
	public string $memory     = '256M';
	public bool $hpos         = true;
	public string $currency   = 'AED';
	public int $decimals      = 2;
	public string $zone       = 'Asia/Dubai';
	public string $country    = 'AE';
	public string $charset    = 'utf8mb4';
	public string $site       = 'Cedar & Stone';
	public string $admin      = 'admin@example.test';
	public bool $visits       = false;
	public string $version    = '0.1.0';

	public function wooVersion(): string {
		return $this->woo;
	}

	public function wpVersion(): string {
		return $this->wp;
	}

	public function phpVersion(): string {
		return $this->php;
	}

	public function databaseVersion(): string {
		return $this->db;
	}

	public function memoryLimit(): string {
		return $this->memory;
	}

	public function hposEnabled(): bool {
		return $this->hpos;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function currencyDecimals(): int {
		return $this->decimals;
	}

	public function timezone(): \DateTimeZone {
		return new \DateTimeZone( $this->zone );
	}

	public function baseCountry(): string {
		return $this->country;
	}

	public function databaseCharset(): string {
		return $this->charset;
	}

	public function siteName(): string {
		return $this->site;
	}

	public function adminEmail(): string {
		return $this->admin;
	}

	public function cronRunsOnVisits(): bool {
		return $this->visits;
	}

	public function adminUrl( string $path = '' ): string {
		return 'https://shop.example.test/wp-admin/' . $path;
	}

	public function pluginVersion(): string {
		return $this->version;
	}
}
