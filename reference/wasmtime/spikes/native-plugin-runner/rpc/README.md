# M2 RPC v1 fixture

M2 uses one private bidirectional pipe pair: host writes requests to the
plugin's stdin and reads plugin requests/responses/notifications from stdout.
Plugin diagnostics use stderr and never share the protocol stream.

Each frame is:

```text
4-byte unsigned big-endian JSON byte length
UTF-8 JSON object
```

The maximum payload is 65,536 bytes. The envelope is deliberately small:

```json
{"protocol":1,"id":"invoke-1","kind":"request","method":"invoke","params":{}}
{"protocol":1,"id":"invoke-1","kind":"response","result":{}}
{"protocol":1,"id":"invoke-1","kind":"response","error":{"code":"cancelled","message":"..."}}
{"protocol":1,"kind":"notification","method":"progress","params":{"stage":"complete"}}
```

Requests and responses carry invocation-local string IDs. Notifications have
no ID. The channel is bidirectional: the fixture plugin requests `host.echo`
while the host is waiting for the invocation response, and the host responds
using the same request ID.

The hello request carries a supported protocol range (`min`, `max`). The
fixture responds with protocol 1 when the ranges intersect, or a typed
`protocol-mismatch` error otherwise. Unknown methods, invalid IDs, invalid JSON,
partial frames, EOF, and oversized frames are protocol failures. The host
classifies plugin exit/EOF separately from malformed framing.

Cancellation is a notification naming the target request ID. The fixture
cooperatively returns a `cancelled` error. A non-cooperative fixture is killed
by the runner deadline, first with TERM and then KILL. M2 does not implement
large resources, HTTP, credentials, or SDK abstractions.
