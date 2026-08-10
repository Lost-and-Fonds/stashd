# UI state matrix

Record states as they are discovered. **Recording a state does not authorize implementing it now.**

| Surface | State | Needed? | Built? | Notes |
| --- | --- | ---: | ---: | --- |
| Stashes list | populated | yes | no | fixture data is fine |
| Stashes list | empty | yes | no | design later |
| Stashes list | loading | likely | no | integration-era behavior may inform this |
| Stashes list | error | likely | no | keep calm/non-alarming |
| Vault | populated | yes | no | |
| Vault | empty | yes | no | |
| Broadcasts | populated | yes | no | |
| Broadcasts | empty | yes | no | |
| Stash detail · Items | populated (paginated) | yes | yes | fixture-backed, 20/page, `UPagination` desktop / Prev-Next mobile |
| Stash detail · Items | filtered/no-results | yes | yes | search+status filter produced zero rows; distinct from the true empty state below |
| Stash detail · Items | true empty (zero Items in Stash) | yes | no | Stash has no preserved media yet — no Inputs synced anything; design later |
| Stash detail · Items | loading | likely | no | integration-era behavior may inform this |
| Stash detail · Items | collection load failure | likely | no | keep calm/non-alarming, consistent with other error states |
| Stash detail · Items | retrying/stale | possible | no | only if a genuine "last known good, refresh failed" case emerges |

## Template

Add rows using:

```text
| <surface> | <state> | yes/no/likely | yes/no | <short note> |
```
