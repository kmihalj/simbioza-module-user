# Deferred Confluence site integration

`confluence-site` preserves the unpublished explicit administrator-led provisioning
of an inactive owner's Personal Workspace, its regression coverage, integration
documentation and associated QA configuration. Automatic sign-in provisioning and
Auth settings are not changed by retaining this development snapshot.

Full-site migration was deferred on 17 October 2026. The working checkout returns
to published `main` (`2df6deb`, 0.3.0). This branch is not a release; candidate
version references require review before any future publication. Resume together
with the matching importer, Calendar and Simbioza branches, without merging merely
to ship the existing single-space importer. Auth and Framework are outside scope.
