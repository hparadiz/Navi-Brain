# Persistence

Application records extend `NaviBrain\Model\ActiveRecord`, which extends Divergence ActiveRecord. Construct a model, set its fields, and call `save()`. Model-specific creation and validation belong on that model.

Reads use Divergence getters. Custom SQL reads go through `Model::getByQuery()` or `Model::getAllByQuery()`. Singleton records use `Model::getByID(1)`.

Application code has no direct PDO calls, transaction wrappers, or Executive persistence overrides. Schema DDL and connection PRAGMAs also pass through `SqliteCatalog::getAllByQuery()`.

`Memory` uses the C token-memory engine through its model API. SQLite holds executive records and operation receipts.
