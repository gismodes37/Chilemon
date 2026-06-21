# Asterisk Configuration — Bridge Reversal

> **Apply manually** on the ASL3 node. These config changes must be in place
> before the bridge can receive inbound IAX2 calls from Asterisk.

## 1. `/etc/asterisk/iax.conf` — IAX2 Peer

Add the following peer definition to `iax.conf`. This tells Asterisk how to
reach the WebRTC bridge as an outbound IAX2 peer.

```ini
[webrtc-bridge]
type=peer
host=127.0.0.1
port=9092
secret=<generated>          ; generate with: openssl rand -hex 16
context=webrtc
qualify=yes                 ; enables PING/PONG keepalive
```

**Note**: The `secret` value must match the IAX2 peer secret expected by the
bridge (not currently used by the bridge itself — the bridge accepts all
inbound calls without auth for v1). The `port=9092` must match
`IAX_LISTEN_PORT` in `/etc/default/chilemon-webrtc`.

After editing, reload:
```bash
asterisk -rx "module reload iax2"
```

## 2. `/etc/asterisk/extensions.conf` — [webrtc] Context

Add the `[webrtc]` context. This context is called when Asterisk completes
the AMI Originate and connects to the bridge via IAX2.

```ini
[webrtc]
exten => 61916,1,rpt(61916|P|WebRTC-P)
```

This extension matches the `Exten=s` + `Context=webrtc` parameters sent
in the AMI Originate. The `rpt()` application connects the call to the
ASL3 node 61916 in phone mode with the "WebRTC-P" (WebRTC Phone) profile.

After editing, reload:
```bash
asterisk -rx "dialplan reload"
```

## 3. `/etc/asterisk/manager.conf` — AMI User

Add the AMI manager user that the bridge uses to authenticate and send
actions (primarily `Originate`).

```ini
[chilemon-bridge]
secret = <from-config>      ; MUST match AMI_PASS in /etc/default/chilemon-webrtc
read = system,call,originate
write = system,call,originate
```

**Permission notes**:
- `call` — required for Originate
- `originate` — required for Originate
- `system` — required for status/commands

The `secret` must match `AMI_PASS` in the bridge environment config.

After editing, reload:
```bash
asterisk -rx "module reload manager"
```

## 4. `/etc/default/chilemon-webrtc` — Bridge Environment

Add the following environment variables to the bridge service's environment
file. These replace the legacy `IAX_HOST`/`IAX_PORT`/`IAX_PHONE_*` variables.

```bash
# AMI connection
AMI_HOST=127.0.0.1
AMI_PORT=5038
AMI_USER=chilemon-bridge
AMI_PASS=<from-manager-conf>    # MUST match manager.conf secret

# IAX2 server
IAX_LISTEN_PORT=9092
```

After editing, restart the bridge:
```bash
systemctl restart chilemon-webrtc
```

## Verification

After applying all config changes and restarting:

1. **Check IAX2 peer**: `asterisk -rx "iax2 show peers"` — should show
   `webrtc-bridge` with status `UNREACHABLE` until bridge is running.

2. **Check AMI user**: `asterisk -rx "manager show users"` — should show
   `chilemon-bridge` with the configured permissions.

3. **Check bridge health**: `curl http://localhost:9091/health` — should
   return `{"ami_connected": true, "iax_server_running": true}`.

## Rollback

1. Remove `[webrtc-bridge]` from `iax.conf`
2. Remove `[webrtc]` context from `extensions.conf`
3. Remove `[chilemon-bridge]` from `manager.conf`
4. Remove AMI/IAX vars from `/etc/default/chilemon-webrtc`
5. Restore old bridge code via git
6. `asterisk -rx "core reload"` and `systemctl restart chilemon-webrtc`
