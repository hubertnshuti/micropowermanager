<?php

namespace App\Plugins\LibreSolarBms\Console\Commands;

use App\Models\Company;
use App\Models\MpmPlugin;
use App\Models\Plugins;
use App\Plugins\LibreSolarBms\Models\Battery;
use App\Services\DatabaseProxyManagerService;
use App\Services\PluginsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException as ProvisioningException;
use Throwable as ProvisioningFailure;

class ProvisionBattery extends Command {
    protected $signature = 'libre-solar-bms:provision {company_id} {node_id}
        {--label=Battery} {--adapter=mpm-demo} {--source=simulator}
        {--key-file= : Private storage path for a new or existing company key}
        {--expires-at= : Optional future UTC expiry, YYYY-MM-DDTHH:MM:SSZ}';
    protected $description = 'Enable the plugin and provision one battery in an explicitly selected company';

    public function handle(DatabaseProxyManagerService $databases, PluginsService $plugins): int {
        $companyId = filter_var($this->argument('company_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $company = $companyId === false ? null : Company::find($companyId);
        $nodeId = (string) $this->argument('node_id');
        $adapter = (string) $this->option('adapter');
        $source = (string) $this->option('source');
        $label = (string) $this->option('label');
        if ($company === null || !preg_match('/^[0-9A-F]{16}$/D', $nodeId)
            || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $adapter) || !in_array($source, ['simulator', 'hardware'], true)
            || trim($label) === '' || mb_strlen($label) > 255) {
            $this->error('Select an existing company, uppercase hexadecimal node, valid adapter/source and a non-empty label.');

            return self::FAILURE;
        }
        $createdFile = null;
        try {
            $keyFile = $this->keyPath();
            $expiresAt = $this->expiry($keyFile);
            $batteryId = DB::connection('micro_power_manager')->transaction(function () use ($company, $databases, $plugins, $nodeId, $adapter, $source, $label, $keyFile, $expiresAt, &$createdFile): int {
                return $databases->runForCompany($company->id, function () use ($company, $plugins, $nodeId, $adapter, $source, $label, $keyFile, $expiresAt, &$createdFile): int {
                    return DB::connection('tenant')->transaction(function () use ($company, $plugins, $nodeId, $adapter, $source, $label, $keyFile, $expiresAt, &$createdFile): int {
                        $battery = Battery::where('node_id', $nodeId)->lockForUpdate()->first();
                        if ($battery !== null && ($battery->adapter_id !== $adapter || $battery->source !== $source)) {
                            throw new ProvisioningException('The node is already bound to another adapter/source. Reconciliation must be explicit.');
                        }
                        if ($keyFile !== null) {
                            $this->ensureKey($company, $keyFile, $expiresAt, $createdFile);
                        }
                        $plugin = $plugins->getByMpmPluginId(MpmPlugin::LIBRE_SOLAR_BMS);
                        if (!$plugin instanceof Plugins) {
                            $plugins->enablePlugin(MpmPlugin::LIBRE_SOLAR_BMS);
                        } else {
                            $plugin->update(['status' => Plugins::ACTIVE]);
                        }
                        if (Artisan::call('libre-solar-bms:install') !== self::SUCCESS) {
                            throw new ProvisioningException('Plugin installation failed.');
                        }
                        $battery ??= Battery::create(['node_id' => $nodeId, 'label' => $label, 'adapter_id' => $adapter, 'source' => $source]);

                        return $battery->id;
                    });
                });
            });
        } catch (ProvisioningFailure $failure) {
            if ($createdFile !== null) {
                $current = @lstat($createdFile['path']);
                if ($current !== false && $current['dev'] === $createdFile['device'] && $current['ino'] === $createdFile['inode'] && !@unlink($createdFile['path'])) {
                    $this->warn('The newly created private key file could not be removed; inspect it before retrying.');
                }
            }
            $this->error($failure instanceof ProvisioningException ? $failure->getMessage() : 'Provisioning failed; no secret has been printed.');
            if (!$failure instanceof ProvisioningException) {
                $this->warn('Central and tenant databases commit separately. A failed final commit can leave tenant provisioning or uncertain central key state; inspect both before retrying.');
            }

            return self::FAILURE;
        }
        $this->info("Battery {$batteryId} provisioned for company {$company->id}; existing metrics and label preserved.");
        if ($keyFile !== null) {
            $this->info('Company key is available in the selected private file; its value is never printed.');
        }

        return self::SUCCESS;
    }

    private function keyPath(): ?string {
        $argument = $this->option('key-file');
        if ($argument === null) {
            return null;
        }
        if ($argument === '' || str_contains($argument, "\0")) {
            throw new ProvisioningException('Select a key file inside storage/app/private.');
        }
        $private = storage_path('app/private');
        if (!is_dir($private) && !mkdir($private, 0700, true) && !is_dir($private)) {
            throw new ProvisioningException('Private storage is unavailable.');
        }
        $root = realpath($private);
        $path = str_starts_with($argument, '/') ? $argument : base_path($argument);
        $parent = realpath(dirname($path));
        if ($root === false || $parent === false || ($parent !== $root && !str_starts_with($parent, $root.'/'))
            || in_array(basename($path), ['.', '..'], true) || is_link($path)) {
            throw new ProvisioningException('Select a regular, non-symlink key file inside storage/app/private; its parent directory must exist.');
        }

        return $parent.'/'.basename($path);
    }

    private function expiry(?string $keyFile): ?Carbon {
        $argument = $this->option('expires-at');
        if ($argument === null) {
            return null;
        }
        if ($keyFile === null || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $argument)) {
            throw new ProvisioningException('Expiry requires --key-file and a future UTC timestamp in YYYY-MM-DDTHH:MM:SSZ format.');
        }
        $expiry = Carbon::createFromFormat('!Y-m-d\TH:i:s\Z', $argument, 'UTC');
        if (!$expiry instanceof Carbon || $expiry->format('Y-m-d\TH:i:s\Z') !== $argument || !$expiry->isFuture()) {
            throw new ProvisioningException('Expiry must be a valid future UTC timestamp.');
        }

        return $expiry;
    }

    /** @param array{path: string, device: int, inode: int}|null $createdFile */
    private function ensureKey(Company $company, string $path, ?Carbon $expiresAt, ?array &$createdFile): void {
        clearstatcache(true, $path);
        if (file_exists($path) || is_link($path)) {
            if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600) {
                throw new ProvisioningException('An existing key file must be a regular non-symlink file with mode 0600.');
            }
            $secret = file_get_contents($path);
            $key = $secret === false || $secret === '' ? null : $company->apiKeys()->active()->where('token_hash', hash('sha256', $secret))->lockForUpdate()->first();
            if ($key === null) {
                throw new ProvisioningException('The private file does not match an active, unexpired key for the selected company. It was not changed.');
            }
            if ($expiresAt instanceof Carbon && ($key->expires_at === null || !$key->expires_at->equalTo($expiresAt))) {
                throw new ProvisioningException('The existing key has a different expiry. Existing keys are never reset.');
            }

            return;
        }
        $secret = bin2hex(random_bytes(32));
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new ProvisioningException('The key file could not be created exclusively. Retry without replacing any existing file.');
        }
        $status = fstat($handle);
        if ($status !== false) {
            $createdFile = ['path' => $path, 'device' => $status['dev'], 'inode' => $status['ino']];
        }
        try {
            if ($status === false || ($status['mode'] & 0777) !== 0600 || fwrite($handle, $secret) !== strlen($secret) || !fflush($handle) || !fsync($handle)) {
                throw new ProvisioningException('The private key file could not be written safely.');
            }
        } finally {
            fclose($handle);
        }
        $company->apiKeys()->create([
            'name' => 'Libre Solar BMS adapter', 'token_hash' => hash('sha256', $secret),
            'active' => true, 'expires_at' => $expiresAt,
        ]);
    }
}
