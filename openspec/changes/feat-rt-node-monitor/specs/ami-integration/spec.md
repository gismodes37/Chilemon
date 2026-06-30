# Delta for ami-integration

## ADDED Requirements

### AMI-COMMAND-01: CLI Command Execution
**Priority**: HIGH

The AMI client MUST support `Action: Command` to execute arbitrary Asterisk CLI commands. The client MUST send `Action: Command\r\nCommand: <cmd>\r\n\r\n` and collect response lines prefixed with `Output:`. The response parser MUST stop collecting at `--END COMMAND--` marker.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Command returns output | AMI client is logged in | `command("rpt lstats 494780")` is called | Response lines are returned as a list of strings |
| Command returns empty | AMI client is logged in | `command("core show version")` returns single line | Single-element list is returned |
| AMI socket error mid-command | Socket error occurs during response read | `command()` is executing | Method returns empty list and logs error without crashing the client |
