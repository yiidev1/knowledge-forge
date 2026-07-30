<?php

declare(strict_types=1);

use App\Ai\Application\Usage\UsageCalculator;
use App\Ai\Application\Usage\UsageMapping;
use App\Ai\Application\Usage\UsagePricing;
use App\Ai\Application\Usage\UsageSnapshot;
use App\Ai\Application\Usage\UsageStoreRow;
use App\Ai\Web\Usage\UsageFormat;
use App\Ai\Web\Usage\UsageSort;
use Yiisoft\Html\Html;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var Yiisoft\View\WebView    $this
 * @var UrlGeneratorInterface   $urlGenerator
 * @var Csrf                    $csrf
 * @var ?UsageSnapshot          $snapshot
 * @var list<UsageStoreRow>     $stores
 * @var UsageSort               $sort
 * @var UsageCalculator         $calculator
 * @var bool                    $adminApiConfigured
 */

$this->setTitle('OpenAI Usage & Vector Stores');
$this->setParameter('breadcrumbs', [['label' => 'OpenAI usage']]);

$syncUrl = $urlGenerator->generate('ai.usage.sync');
$csrfField = (string) $csrf->hiddenInput();

$mappings = $snapshot?->mappings ?? [];
$problemMappings = array_values(array_filter($mappings, static fn(UsageMapping $m): bool => $m->isProblem()));
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">OpenAI Usage &amp; Vector Stores</h1>
        <p class="page-header__subtitle">
            Read-only view of the vector stores in your OpenAI account, their storage, and how they line
            up with the knowledge bases in this application.
        </p>
    </div>
    <form method="post" action="<?= Html::encode($syncUrl) ?>">
        <?= $csrfField ?>
        <button type="submit" class="btn btn--primary" data-busy="Syncing…">Sync latest data</button>
    </form>
</div>

<?php if ($snapshot === null): ?>
    <div class="empty">
        <div class="empty__icon" aria-hidden="true">☁</div>
        <div class="empty__title">Not synced yet</div>
        <p>
            Nothing has been fetched from OpenAI yet. Opening this page never calls the API on its own —
            choose <strong>Sync latest data</strong> to fetch the current inventory.
        </p>
    </div>
<?php else: ?>
    <?php $totals = $snapshot->totals; ?>

    <?php if ($snapshot->truncated): ?>
        <div class="alert alert--warning">
            <span class="alert__icon" aria-hidden="true">!</span>
            <div>
                <strong>Partial view.</strong>
                The last sync stopped at its safety limits, so the figures below are a floor rather than
                a complete total.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($snapshot->hasProblems()): ?>
        <div class="alert alert--warning">
            <span class="alert__icon" aria-hidden="true">!</span>
            <div>
                <strong>Some data could not be fetched.</strong>
                <ul>
                    <?php foreach ($snapshot->problems as $problem): ?>
                        <li>
                            <?= Html::encode($problem->source) ?><?php if ($problem->subject !== null): ?>
                                (<span class="util-mono"><?= Html::encode($problem->subject) ?></span>)
                            <?php endif; ?>:
                            <?= Html::encode($problem->message) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <p class="util-muted">
            Last synced <strong><?= Html::encode($snapshot->syncedAt->format('Y-m-d H:i')) ?> UTC</strong>.
            Inventory figures are live values read from OpenAI. Cost figures are
            <strong>estimates</strong> calculated from this snapshot, not an invoice — OpenAI bills
            storage by time, so a store added or removed between syncs will not match exactly.
        </p>
    </div>

    <h2 class="card__title">Inventory <span class="badge badge--info"><span class="badge__dot"></span>Live</span></h2>
    <div class="grid grid--stats">
        <div class="stat">
            <div class="stat__label">Vector stores</div>
            <div class="stat__value"><?= $totals->storeCount ?></div>
            <div class="stat__hint"><?= $totals->storesWithStatus('completed') ?> completed
                · <?= $totals->storesWithStatus('in_progress') ?> in progress
                · <?= $totals->storesWithStatus('expired') ?> expired</div>
        </div>
        <div class="stat">
            <div class="stat__label">Attached files</div>
            <div class="stat__value"><?= $totals->fileCounts->total ?></div>
            <div class="stat__hint"><?= $totals->fileCounts->completed ?> completed
                · <?= $totals->fileCounts->inProgress ?> in progress
                · <?= $totals->fileCounts->failed ?> failed</div>
        </div>
        <div class="stat">
            <div class="stat__label">Total storage</div>
            <div class="stat__value"><?= Html::encode(UsageFormat::bytes($totals->totalUsageBytes)) ?></div>
            <div class="stat__hint"><?= Html::encode(UsageFormat::gib($totals->totalGib)) ?> across all stores</div>
        </div>
    </div>

    <h2 class="card__title">Estimated cost <span class="badge badge--warning"><span class="badge__dot"></span>Estimated</span></h2>
    <div class="grid grid--stats">
        <div class="stat">
            <div class="stat__label">Free allowance</div>
            <div class="stat__value"><?= Html::encode(number_format(UsagePricing::FREE_STORAGE_GIB, 0)) ?> GiB</div>
            <div class="stat__hint">Applied once to the account total, not per store</div>
        </div>
        <div class="stat">
            <div class="stat__label">Billable storage</div>
            <div class="stat__value"><?= Html::encode(UsageFormat::gib($totals->billableGib)) ?></div>
            <div class="stat__hint">Total less the free allowance</div>
        </div>
        <div class="stat">
            <div class="stat__label">Estimated per day</div>
            <div class="stat__value"><?= Html::encode(UsageFormat::money($totals->estimatedDailyCost)) ?></div>
            <div class="stat__hint">At <?= Html::encode(UsageFormat::money(UsagePricing::STORAGE_USD_PER_GIB_PER_DAY)) ?> per GiB per day</div>
        </div>
        <div class="stat">
            <div class="stat__label">Estimated 30 days</div>
            <div class="stat__value"><?= Html::encode(UsageFormat::money($totals->estimatedProjectedCost)) ?></div>
            <div class="stat__hint">Projection if storage stays as it is now</div>
        </div>
    </div>

    <h2 class="card__title">Organization billing <span class="badge badge--muted"><span class="badge__dot"></span>Unavailable</span></h2>
    <div class="card">
        <p class="util-muted">
            <?php if ($adminApiConfigured): ?>
                An organization admin key is configured, but organization reporting is not enabled in this
                build. Actual billed cost, File Search call history and token usage remain unavailable.
            <?php else: ?>
                <strong>Unavailable — OpenAI Admin API key is not configured.</strong>
                Actual billed cost, historical File Search call usage and token usage come from the
                organization reporting endpoints, which need a separate admin-scoped key
                (<span class="util-mono">OPENAI_ADMIN_API_KEY</span>). Everything above is derived from
                the vector-store inventory and does not need one.
            <?php endif; ?>
        </p>
    </div>

    <h2 class="card__title">Vector stores</h2>
    <?php if ($stores === []): ?>
        <div class="empty">
            <div class="empty__title">No vector stores</div>
            <p>The account has no vector stores.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('name')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'name')) ?>"><?= Html::encode('Name' . $sort->markerFor('name')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('status')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'status')) ?>"><?= Html::encode('Status' . $sort->markerFor('status')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('storage')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'storage')) ?>"><?= Html::encode('Storage' . $sort->markerFor('storage')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('files')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'files')) ?>"><?= Html::encode('Files' . $sort->markerFor('files')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('created')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'created')) ?>"><?= Html::encode('Created' . $sort->markerFor('created')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('last_active')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'last_active')) ?>"><?= Html::encode('Last active' . $sort->markerFor('last_active')) ?></a></th>
                        <th aria-sort="<?= Html::encode($sort->ariaFor('expires')) ?>"><a href="<?= Html::encode($sort->linkFor($urlGenerator, 'expires')) ?>"><?= Html::encode('Expires' . $sort->markerFor('expires')) ?></a></th>
                        <th>Est. per day</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stores as $store): ?>
                        <?php
                        $mapping = null;
                        foreach ($mappings as $candidate) {
                            if ($candidate->remoteVectorStoreId === $store->id) {
                                $mapping = $candidate;
                                break;
                            }
                        }
                        $share = $calculator->apportionedDailyCost($store->usageBytes, $totals->totalUsageBytes);
                        ?>
                        <tr>
                            <td>
                                <strong><?= Html::encode($store->name !== '' ? $store->name : '(unnamed)') ?></strong>
                                <div class="field__hint">
                                    <span class="util-mono" title="<?= Html::encode($store->id) ?>"><?= Html::encode(UsageFormat::shortId($store->id)) ?></span>
                                    <button type="button" class="btn btn--secondary btn--sm" data-copy="<?= Html::encode($store->id) ?>" hidden>Copy ID</button>
                                </div>
                                <?php if ($mapping !== null && $mapping->knowledgeBaseName !== null): ?>
                                    <div class="field__hint">Knowledge base: <?= Html::encode($mapping->knowledgeBaseName) ?></div>
                                <?php elseif ($mapping !== null): ?>
                                    <div class="field__hint"><span class="badge badge--warning"><span class="badge__dot"></span>Not mapped here</span></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge--<?= Html::encode(UsageFormat::statusBadge($store->status)) ?>"><span class="badge__dot"></span><?= Html::encode($store->status) ?></span></td>
                            <td><?= Html::encode(UsageFormat::bytes($store->usageBytes)) ?></td>
                            <td>
                                <?= $store->fileCounts->total ?>
                                <div class="field__hint">
                                    <?= $store->fileCounts->completed ?> ok
                                    <?php if ($store->fileCounts->inProgress > 0): ?>· <?= $store->fileCounts->inProgress ?> pending<?php endif; ?>
                                    <?php if ($store->fileCounts->failed > 0): ?>· <?= $store->fileCounts->failed ?> failed<?php endif; ?>
                                </div>
                            </td>
                            <td class="util-muted"><?= Html::encode(UsageFormat::moment($store->createdAt)) ?></td>
                            <td class="util-muted"><?= Html::encode(UsageFormat::moment($store->lastActiveAt)) ?></td>
                            <td class="util-muted"><?= Html::encode(UsageFormat::moment($store->expiresAt)) ?></td>
                            <td><?= Html::encode(UsageFormat::money($share)) ?></td>
                        </tr>
                        <tr>
                            <td colspan="8">
                                <details>
                                    <summary>Details for <?= Html::encode($store->name !== '' ? $store->name : UsageFormat::shortId($store->id)) ?></summary>
                                    <div class="util-mt">
                                        <div class="field">
                                            <label class="field__label" for="vs-<?= Html::encode($store->id) ?>">Full vector store ID</label>
                                            <input class="field__control util-mono" id="vs-<?= Html::encode($store->id) ?>" type="text" readonly value="<?= Html::encode($store->id) ?>">
                                        </div>

                                        <?php if ($store->metadata !== []): ?>
                                            <p class="field__hint">Metadata</p>
                                            <div class="table-wrap">
                                                <table class="table">
                                                    <tbody>
                                                        <?php foreach ($store->metadata as $key => $value): ?>
                                                            <tr>
                                                                <td class="util-mono"><?= Html::encode($key) ?></td>
                                                                <td class="util-mono"><?= Html::encode($value) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($store->fileDetailProblem !== null): ?>
                                            <p class="util-muted">File detail unavailable: <?= Html::encode($store->fileDetailProblem) ?></p>
                                        <?php elseif ($store->files === []): ?>
                                            <p class="util-muted">No files returned for this store.</p>
                                        <?php else: ?>
                                            <div class="table-wrap">
                                                <table class="table">
                                                    <thead>
                                                        <tr>
                                                            <th>File ID</th>
                                                            <th>Status</th>
                                                            <th>Size</th>
                                                            <th>Created</th>
                                                            <th>Error</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($store->files as $file): ?>
                                                            <tr>
                                                                <td class="util-mono" title="<?= Html::encode($file->id) ?>"><?= Html::encode(UsageFormat::shortId($file->id)) ?></td>
                                                                <td><span class="badge badge--<?= Html::encode(UsageFormat::statusBadge($file->status)) ?>"><span class="badge__dot"></span><?= Html::encode($file->status) ?></span></td>
                                                                <td><?= Html::encode(UsageFormat::bytes($file->usageBytes)) ?></td>
                                                                <td class="util-muted"><?= Html::encode(UsageFormat::moment($file->createdAt)) ?></td>
                                                                <td class="util-muted"><?= Html::encode($file->lastErrorMessage ?? '—') ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="card__title">Knowledge base reconciliation</h2>
    <div class="card">
        <p class="util-muted">
            Compares each knowledge base against the remote inventory. This view is read-only: nothing
            here deletes, detaches or re-provisions anything. An unmapped remote store is reported, not
            removed — another environment may share this OpenAI account.
        </p>
        <?php if ($problemMappings === []): ?>
            <p><span class="badge badge--success"><span class="badge__dot"></span>Everything matches</span></p>
        <?php endif; ?>
    </div>

    <?php if ($mappings !== []): ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Knowledge base</th>
                        <th>Mapping</th>
                        <th>Local status</th>
                        <th>Remote status</th>
                        <th>Documents</th>
                        <th>Remote files</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mappings as $mapping): ?>
                        <?php
                        [$label, $badge] = match ($mapping->state) {
                            UsageMapping::STATE_MATCHED => ['Matched', 'success'],
                            UsageMapping::STATE_REMOTE_UNMAPPED => ['Remote store not mapped to this application', 'warning'],
                            UsageMapping::STATE_LOCAL_MISSING_REMOTE => ['Knowledge base references a missing remote store', 'error'],
                            UsageMapping::STATE_STATUS_MISMATCH => ['Local and remote status disagree', 'warning'],
                            default => ['Not provisioned yet', 'muted'],
                        };
                        ?>
                        <tr>
                            <td>
                                <?php if ($mapping->knowledgeBaseName !== null): ?>
                                    <strong><?= Html::encode($mapping->knowledgeBaseName) ?></strong>
                                    <div class="field__hint">
                                        <span class="util-mono"><?= Html::encode((string) $mapping->knowledgeBaseSlug) ?></span>
                                        · #<?= $mapping->knowledgeBaseId ?>
                                        <?php if ($mapping->archived): ?>
                                            <span class="badge badge--muted"><span class="badge__dot"></span>Archived</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="util-muted">—</span>
                                <?php endif; ?>
                                <?php if ($mapping->remoteVectorStoreId !== null): ?>
                                    <div class="field__hint util-mono" title="<?= Html::encode($mapping->remoteVectorStoreId) ?>"><?= Html::encode(UsageFormat::shortId($mapping->remoteVectorStoreId)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge--<?= Html::encode($badge) ?>"><span class="badge__dot"></span><?= Html::encode($label) ?></span></td>
                            <td class="util-muted"><?= Html::encode($mapping->localVectorStoreStatus ?? '—') ?></td>
                            <td class="util-muted"><?= Html::encode($mapping->remoteStatus ?? '—') ?></td>
                            <td>
                                <?php if ($mapping->localDocumentCount === null): ?>
                                    <span class="util-muted">—</span>
                                <?php else: ?>
                                    <?= $mapping->localReadyDocumentCount ?> ready / <?= $mapping->localDocumentCount ?> total
                                <?php endif; ?>
                            </td>
                            <td><?= $mapping->remoteFileCount === null ? '—' : $mapping->remoteFileCount ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
