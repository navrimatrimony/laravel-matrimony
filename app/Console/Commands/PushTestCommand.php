<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\SuchakAccount;
use App\Models\User;
use App\Services\Push\PushDispatchService;
use App\Services\Push\FirebasePushService;
use Illuminate\Console\Command;

/**
 * Fire one push at a real phone and see it land.
 *
 * Deliberately bypasses the admin/user switchboard: this proves the TRANSPORT
 * (credentials, OAuth, network, token) works, which must be verifiable while
 * every notification type is still switched off.
 */
class PushTestCommand extends Command
{
    protected $signature = 'push:test
        {token? : FCM registration token. Omit and use --user or --suchak to look one up.}
        {--user= : Send to every member-app device of this user id}
        {--suchak= : Send to every Suchak-app device of this suchak account id}
        {--title= : Notification title}
        {--body= : Notification body}';

    protected $description = 'Send a test push notification through Firebase Cloud Messaging (HTTP v1).';

    public function handle(FirebasePushService $firebase, PushDispatchService $dispatcher): int
    {
        if (! (bool) config('engagement.push.enabled', false)) {
            $this->error('Push is disabled. Set ENGAGEMENT_PUSH_ENABLED=true in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        $this->line('Credentials: '.$firebase->credentialsPath());

        if (! $firebase->enabled()) {
            $this->error('Service account file is missing, unreadable, or not valid JSON.');

            return self::FAILURE;
        }

        $this->line('Project id:  '.($firebase->projectId() ?? '(unknown)'));

        $tokens = $this->resolveTokens($dispatcher);
        if ($tokens === []) {
            $this->error('No device token to send to. Pass a token argument, or --user / --suchak with a registered device.');

            return self::FAILURE;
        }

        $title = (string) ($this->option('title') ?: 'Navri test');
        $body = (string) ($this->option('body') ?: 'Push is working.');

        $this->line(sprintf('Sending to %d device(s)…', count($tokens)));

        $result = $firebase->sendToTokens($tokens, $title, $body, [
            'type' => 'test',
            'target' => 'notifications',
        ]);

        foreach ($result['results'] as $token => $outcome) {
            $this->line(sprintf(
                '  %s …%s%s',
                $outcome['ok'] ? '<info>OK  </info>' : '<error>FAIL</error>',
                substr((string) $token, -10),
                $outcome['ok'] ? '' : '  '.$outcome['error'].($outcome['pruned'] ? ' (token row deleted)' : ''),
            ));
        }

        $this->newLine();
        $this->line(sprintf('sent=%d failed=%d pruned=%d', $result['sent'], $result['failed'], $result['pruned']));

        return $result['sent'] > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function resolveTokens(PushDispatchService $dispatcher): array
    {
        $token = trim((string) $this->argument('token'));
        if ($token !== '') {
            return [$token];
        }

        if ($userId = $this->option('user')) {
            $user = User::find($userId);
            if (! $user) {
                $this->error('User '.$userId.' not found.');

                return [];
            }

            return $dispatcher->tokensForOwner($user, DeviceToken::APP_MEMBER);
        }

        if ($suchakId = $this->option('suchak')) {
            $account = SuchakAccount::find($suchakId);
            if (! $account) {
                $this->error('Suchak account '.$suchakId.' not found.');

                return [];
            }

            return $dispatcher->tokensForOwner($account, DeviceToken::APP_SUCHAK);
        }

        return [];
    }
}
