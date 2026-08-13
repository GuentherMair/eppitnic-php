<?php

namespace Eppitnic\Cli;

/**
 * The caller got the command line wrong: an unknown option, a missing value,
 * an unreadable input file. Reported with the usage text and exit code
 * SYNTAX_ERROR, never with a stack trace -- it is not a bug.
 */
final class UsageError extends \RuntimeException
{
}
