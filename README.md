# drupal-module-advanced-reports

A module which provides user report outputs that are mash-ups, Elasticsearch based, or more complex than simple reporting services calls.

Available reports:

- `/api/v2/advanced_reports/user-stats`
- `/api/v2/advanced_reports/counts`
- `/api/v2/advanced_reports/recorded-taxa-list`

The endpoints require user authentication. For this an OAuth2 token can be used. For example, add a header to the request `'Authorization': 'Bearer JWT_TOKEN_HERE'`.
