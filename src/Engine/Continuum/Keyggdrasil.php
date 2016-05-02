<?php
declare(strict_types=1);

namespace Airship\Engine\Continuum;

use \Airship\Alerts\Continuum\{
    ChannelSignatureFailed,
    CouldNotUpdate,
    PeerSignatureFailed
};
use \Airship\Engine\{
    Bolt\Supplier as SupplierBolt,
    Bolt\Log,
    Contract\DBInterface,
    Hail,
    State
};
use \GuzzleHttp\Psr7\Response;
use \GuzzleHttp\Exception\TransferException;
use \ParagonIE\ConstantTime\Base64UrlSafe;
use \ParagonIE\Halite\{
    Asymmetric\Crypto as AsymmetricCrypto,
    Structure\MerkleTree,
    Structure\Node
};
use \Psr\Log\LogLevel;

/**
 * Class Keygdrassil
 *
 * (Yggdrasil = "world tree")
 *
 * This synchronizes our public keys for each channel with the rest of the network,
 * taking care to verify that a random subset of trusted peers sees the same keys.
 *
 * @package Airship\Engine\Continuum
 */
class Keyggdrasil
{
    use SupplierBolt;
    use Log;

    protected $db;
    protected $hail;
    protected $supplierCache;
    protected $channelCache;

    /**
     * Keyggdrasil constructor.
     *
     * @param Hail|null $hail
     * @param DBInterface|null $db
     * @param array $channels
     */
    public function __construct(Hail $hail = null, DBInterface $db = null, array $channels = [])
    {
        $config = State::instance();
        if (empty($hail)) {
            $this->hail = $config->hail;
        } else {
            $this->hail = $hail;
        }

        if (!$db) {
            $db = \Airship\get_database();
        }
        $this->db = $db;

        foreach ($channels as $ch => $config) {
            $this->channelCache[$ch] = new Channel($this, $ch, $config);
        }
    }

    /**
     * Does this peer notary see the same Merkle root?
     *
     * @param Peer $peer
     * @param string $expectedRoot
     * @return bool
     * @throws CouldNotUpdate
     * @throws PeerSignatureFailed
     */
    protected function checkWithPeer(Peer $peer, string $expectedRoot): bool
    {
        foreach ($peer->getAllURLs() as $url) {
            $challenge = Base64UrlSafe::encode(\random_bytes(33));
            $response = $this->hail->post($url, [
                'challenge' => $challenge
            ]);
            if ($response instanceof Response) {
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    $body = (string) $response->getBody();
                    $response = \json_decode($body, true);
                    if ($response['status'] === 'OK') {
                        // Decode then verify signature
                        $message = Base64UrlSafe::decode($response['response']);
                        $signature = Base64UrlSafe::decode($response['signature']);
                        if (!AsymmetricCrypto::verify($message, $peer->getPublicKey(), $signature, true)) {
                            throw new PeerSignatureFailed(
                                'Invalid digital signature (i.e. it was signed with an incorrect key).'
                            );
                        }
                        // Make sure our challenge was signed.
                        $decoded = \json_decode($message, true);
                        if (!\hash_equals($challenge, $decoded['challenge'])) {
                            throw new CouldNotUpdate(
                                'Challenge-response authentication failed.'
                            );
                        }
                        // Make sure this was a recent signature (it *should* be):
                        $min = (new \DateTime('now'))
                            ->sub(new \DateInterval('P01D'));
                        $time = new \DateTime($decoded['timestamp']);
                        if ($time < $min) {
                            throw new CouldNotUpdate(
                                'Timestamp ' . $decoded['timestamp'] . ' is far too old.'
                            );
                        }

                        // Return TRUE if it matches the expected root.
                        // Return FALSE if it matches.
                        return \hash_equals(
                            $expectedRoot,
                            $decoded['root']
                        );
                    }
                    // If we're still here, the Peer returned an error.
                }
                // If we're still here, the Peer returned an HTTP error.
            }
            // If we're still here, Guzzle failed.
        }
        // When all else fails, throw a TransferException
        throw new TransferException();
    }

    /**
     * Launch the update process.
     *
     * This updates our keys for each channel.
     */
    public function doUpdate()
    {
        if (empty($this->channelCache)) {
            return;
        }
        foreach ($this->channelCache as $chan) {
            $this->updateChannel($chan);
        }
    }

    /**
     * Fetch all of the updates from the remote server.
     *
     * @param Channel $chan
     * @param string $url
     * @param string $root Which Merkle root are we starting at?
     * @return KeyUpdate[]
     * @throws TransferException
     */
    protected function fetchKeyUpdates(Channel $chan, string $url, string $root): array
    {
        $response = $this->hail->post(
            $url . API::get('fetch_keys') . '/' . $root
        );
        if ($response instanceof Response) {
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $body = (string) $response->getBody();

                // This should return an array of KeyUpdate objects:
                return $this->parseKeyUpdateResponse($chan, $body);
            }
        }
        // When all else fails, TransferException
        throw new TransferException();
    }

    /**
     * Get the tree of existing Merkle roots.
     *
     * @param Channel $chan
     * @return MerkleTree
     */
    protected function getMerkleTree(Channel $chan): MerkleTree
    {
        $nodeList = [];
        $queryString = 'SELECT data FROM airship_key_updates WHERE channel = ? ORDER BY keyupdateid ASC';
        foreach ($this->db->run($queryString, $chan->getName()) as $node) {
            $nodeList []= new Node($node['data']);
        }
        return new MerkleTree(...$nodeList);
    }

    /**
     * Interpret the KeyUpdate objects from the API response. OR verify the signature
     * of the "no updates" message to prevent a DoS.
     *
     * @param Channel $chan
     * @param string $body
     * @return KeyUpdate[]
     * @throws ChannelSignatureFailed
     * @throws CouldNotUpdate
     */
    protected function parseKeyUpdateResponse(Channel $chan, string $body): array
    {
        $response = \Airship\parseJSON($body);
        if (empty($response['updates'])) {
            // The "no updates" message should be authenticated.
            if (!AsymmetricCrypto::verify($response['no_updates'], $chan->getPublicKey(), $response['signature'])) {
                throw new ChannelSignatureFailed();
            }
            $datetime = new \DateTime($response['no_updates']);

            // One hour ago:
            $stale = (new \DateTime('now'))
                ->sub(new \DateInterval('PT01H'));

            if ($datetime < $stale) {
                throw new CouldNotUpdate('Stale response.');
            }

            // We got nothing to do:
            return [];
        }

        $keyUpdateArray = [];
        foreach ($response['updates'] as $update) {
            $data = Base64UrlSafe::decode($update['data']);
            $sig = Base64UrlSafe::decode($update['signature']);
            if (!AsymmetricCrypto::verify($data, $chan->getPublicKey(), $sig, true)) {
                // Invalid signature
                throw new ChannelSignatureFailed();
            }
            // Now that we know it was signed by the channel, time to update
            $keyUpdateArray[] = new KeyUpdate(
                $chan,
                \json_decode($data, true)
            );
        }
        // Sort by ID
        \uasort(
            $keyUpdateArray,
            function (KeyUpdate $a, KeyUpdate $b): array
            {
                return $a->getChannelId() <=> $b->getChannelId();
            }
        );
        return $keyUpdateArray;
    }

    /**
     * Insert/delete entries in supplier_keys, while updating the database.
     *
     * Return the updated Merkle Tree if all is well
     *
     * @param Channel $chan)
     * @param KeyUpdate[] $updates
     */
    protected function processKeyUpdates(Channel $chan, KeyUpdate ...$updates)
    {
        /**
         * Last piece before field testing.
         */
    }

    /**
     * Update a particular channel.
     *
     * 1. Identify a working URL for the channel.
     * 2. Query server for updates.
     * 3. For each update:
     *    1. Verify that our trusted notaries see the same update.
     *       (Ed25519 signature of challenge nonce || Merkle root)
     *    2. Add/remove the supplier's key.
     *
     * @param Channel $chan
     */
    protected function updateChannel(Channel $chan)
    {
        $originalTree = $this->getMerkleTree($chan);
        foreach ($chan->getAllURLs() as $url) {
            try {
                $updates = $this->fetchKeyUpdates($chan, $url, $originalTree->getRoot()); // KeyUpdate[]
                while (!empty($updates)) {
                    $merkleTree = $originalTree;
                    // Verify these updates with our Peers.
                    try {
                        if ($this->verifyResponseWithPeers($chan, $merkleTree, ...$updates)) {
                            // Apply these updates:
                            $this->processKeyUpdates($chan, ...$updates);
                            return;
                        }
                    } catch (CouldNotUpdate $ex) {
                        $this->log(
                            $ex->getMessage(),
                            LogLevel::ALERT,
                            \Airship\throwableToArray($ex)
                        );
                    }
                    // If verification fails, pop off the last update and try again
                    \array_pop($updates);
                }
                // Received a successful API response.
                return;
            } catch (ChannelSignatureFailed $ex) {
                $this->log(
                    'Invalid Channel Signature for ' . $chan->getName(),
                    LogLevel::ALERT,
                    \Airship\throwableToArray($ex)
                );
            } catch (TransferException $ex) {
                $this->log(
                    'Channel update error',
                    LogLevel::NOTICE,
                    \Airship\throwableToArray($ex)
                );
            }
        }
        // IF we get HERE, we've run out of updates to try.

        $this->log('Channel update concluded with no changes', LogLevel::ALERT);
    }

    /**
     * Return true if the Merkle roots match.
     *
     * This employs challenge-response authentication:
     * @ref https://github.com/paragonie/airship/issues/13
     *
     * @param Channel $channel
     * @param MerkleTree $originalTree
     * @param KeyUpdate[] ...$updates
     * @return bool
     * @throws CouldNotUpdate
     */
    protected function verifyResponseWithPeers(
        Channel $channel,
        MerkleTree $originalTree,
        KeyUpdate ...$updates
    ): bool {
        $state = State::instance();
        $nodes = $this->updatesToNodes($updates);
        $tree = $originalTree->getExpandedTree(...$nodes);

        $maxUpdateIndex = \count($updates) - 1;
        $expectedRoot = $updates[$maxUpdateIndex]->getRoot();
        if (\hash_equals($tree->getRoot(), $expectedRoot)) {
            // Calculated root did not match.
            throw new CouldNotUpdate(
                'Calculated Merkle root did not match the update.'
            );
        }

        if ($state->univeral['auto-update']['ignore-peer-verification']) {
            // The user has expressed no interest in verification
            return true;
        }

        $peers = $channel->getPeerList();
        $numPeers = \count($peers);
        $minSuccess = $channel->getAppropriatePeerSize();
        $maxFailure = (int) \min(
            \floor($minSuccess * M_E),
            $numPeers - 1
        );
        if ($maxFailure < 1) {
            $maxFailure = 1;
        }
        \Airship\secure_shuffle($peers);

        $success = $networkError = 0;

        for ($i = 0; $i < $numPeers; ++$i) {
            try {
                if (!$this->checkWithPeer($peers[$i], $tree->getRoot())) {
                    // Merkle root mismatch? Abort.
                    return false;
                }
                ++$success;
            } catch (TransferException $ex) {
                ++$networkError;
            }

            if ($success >= $minSuccess) {
                // We have enough good responses.
                return true;
            } elseif ($networkError >= $maxFailure) {
                return false;
            }
        }
        // Fail closed:
        return false;
    }

    /**
     * Get a bunch of nodes for inclusion in the Merkle tree.
     *
     * @param KeyUpdate[] $updates
     * @return Node[]
     */
    protected function updatesToNodes(array $updates): array
    {
        $return = [];
        foreach ($updates as $up) {
            $return []= new Node($up->getNodeJSON());
        }
        return $return;
    }
}
