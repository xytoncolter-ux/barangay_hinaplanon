<?php
// No historical row is treated as source-reconciled merely because an import set is_verified=1.
// After checking original documents, record the exact row hash, reviewer and source reference.
// public_release must explicitly be true before an otherwise reconciled historical row reaches residents.
// See PREPAREDNESS_MANAGEMENT.md. This configuration is server-side, not a publication feed.
return ['reconciled_history' => []];
