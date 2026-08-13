<?php

declare(strict_types=1);

use App\Reports\Contract\ChatReportReaderInterface;
use App\Reports\Infrastructure\DbChatReportReader;

// The Reports module: one read-only port for the admin chat report. The action is autowired.
return [
    ChatReportReaderInterface::class => DbChatReportReader::class,
];
