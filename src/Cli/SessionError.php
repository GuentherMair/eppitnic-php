<?php

namespace Eppitnic\Cli;

/**
 * The registry session could not be established -- unreachable server, or a
 * rejected login. Distinct from a command that ran and failed: nothing was
 * attempted, so retrying later is the sensible response.
 */
final class SessionError extends \RuntimeException
{
}
