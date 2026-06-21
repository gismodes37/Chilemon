# AMI Integration — Full Spec

**Domain**: `ami-integration`
**Change**: `bridge-reversal`
**Type**: Full spec (no baseline)

## ADDED Requirements

### AMI-CONNECT-01: TCP Connection
**Priority**: HIGH

The AMI client MUST open a TCP socket to `127.0.0.1:5038` on bridge startup.
It MUST read the Asterisk banner before sending the login action.

**Scenario**: Successful connect
  Given AMI is enabled on the Asterisk server at 127.0.0.1:5038
  When the bridge starts
  Then the AMI client establishes a TCP connection
  And it reads the initial banner line

**Scenario**: Connection refused
  Given Asterisk is not running or AMI is disabled
  When the bridge attempts to connect
  Then the AMI client MUST log the error
  And the bridge continues running (health shows degraded)

### AMI-CONNECT-02: Login
**Priority**: HIGH

After connecting, the AMI client MUST send `Action: Login` with credentials from config/local.php (`ami_user`, `ami_pass`). Events MUST be enabled (`Events: on`).

**Scenario**: Successful login
  Given a TCP connection to AMI is established
  When the client sends Login with valid credentials
  Then it receives `Response: Success`
  And the client is ready to send actions

**Scenario**: Authentication failure
  Given a TCP connection to AMI is established
  When the client sends Login with invalid credentials
  Then it MUST log the error
  And the bridge is marked degraded

### AMI-ORIGINATE-01: Originate Action
**Priority**: HIGH

The AMI client MUST support `Action: Originate` to initiate outbound calls.
Parameters: `Channel` (IAX2/webrtc-bridge), `Context` (webrtc), `Exten` (node number), `Priority` (1), `CallerID` (bridge identifier), `Async` (yes), `Timeout` (15000).

**Scenario**: Originate initiates call
  Given the AMI client is logged in
  When `originate()` is called with a valid node number
  Then the client sends `Action: Originate` with correct parameters
  And Asterisk initiates an IAX2 call to the bridge

### AMI-EVENT-01: Event Monitoring
**Priority**: MEDIUM

The AMI client MUST read asynchronous events from the AMI socket after login. A background task MUST parse event responses and dispatch to registered callbacks.

**Scenario**: Hangup event received
  Given a call is active via Originate
  When Asterisk sends a `Hangup` event
  Then the event callback is invoked
  And the bridge resets call state

**Scenario**: Newstate event received
  Given an Originate is in progress
  When Asterisk sends a `Newstate` event with `ChannelState = 6` (Up)
  Then the callback signals that the call is connected

### AMI-TIMEOUT-01: Originate Timeout
**Priority**: MEDIUM

The AMI client MUST enforce a 15-second timeout for Originate responses.
If no response or no call establishment is detected within 15s, it MUST log and return failure.

**Scenario**: Originate times out
  Given the Originate action was sent
  When no call establishment event arrives within 15 seconds
  Then the bridge reports the call as failed
  And logs the timeout event

### AMI-CONFIG-01: Credentials from Environment
**Priority**: HIGH

The client MUST read AMI credentials from environment variables:
- `AMI_HOST` (default: 127.0.0.1)
- `AMI_PORT` (default: 5038)
- `AMI_USER` (default: admin)
- `AMI_PASS` (required, no default)
- `AMI_TIMEOUT` (default: 15)

If `AMI_PASS` is empty, the bridge MUST fail to start.

### AMI-LIFECYCLE-01: Graceful Disconnect
**Priority**: LOW

On bridge shutdown, the AMI client MUST send `Action: Logoff` and close the TCP socket.
