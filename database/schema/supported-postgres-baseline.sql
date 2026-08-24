--
-- PostgreSQL database dump
--


-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6




--
-- Name: activity_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.activity_events (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    level text NOT NULL,
    type character varying(255) NOT NULL,
    message text NOT NULL,
    "entityType" character varying(255),
    "entityId" character varying(40),
    "stashId" character varying(40),
    "mediaItemId" character varying(40),
    "broadcastId" character varying(40),
    "jobId" character varying(40),
    "commandId" character varying(40),
    "groupKey" character varying(255),
    metadata text
);


--
-- Name: api_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.api_tokens (
    id character varying(40) NOT NULL,
    "userId" character varying(40) NOT NULL,
    name character varying(255) NOT NULL,
    "tokenHash" character varying(255) NOT NULL,
    "tokenPreview" character varying(255),
    scopes text,
    "lastUsedAt" timestamp without time zone,
    "expiresAt" timestamp without time zone,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "revokedAt" timestamp without time zone
);


--
-- Name: assets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.assets (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "mediaItemId" character varying(40),
    "broadcastId" character varying(40),
    "broadcastItemId" character varying(40),
    role text NOT NULL,
    kind text NOT NULL,
    path text,
    "relativePath" text,
    "mimeType" character varying(255),
    container character varying(255),
    "videoCodec" character varying(255),
    "audioCodec" character varying(255),
    language character varying(255),
    "sizeBytes" bigint,
    checksum character varying(255),
    "durationSeconds" integer,
    "derivedFromAssetId" character varying(40),
    state text DEFAULT 'pending'::text NOT NULL,
    "lastVerifiedAt" timestamp without time zone,
    "missingAt" timestamp without time zone,
    "missingReason" character varying(255)
);


--
-- Name: broadcast_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcast_items (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "broadcastId" character varying(40) NOT NULL,
    "stashItemId" character varying(40) NOT NULL,
    "mediaItemId" character varying(40) NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    "publishedPath" text,
    "publishedUri" text,
    "lastPublishedAt" timestamp without time zone,
    "lastVerifiedAt" timestamp without time zone,
    "lastError" text,
    "tokenSecretId" character varying(40),
    "tokenPreview" character varying(255)
);


--
-- Name: broadcast_sponsorblock_refreshes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcast_sponsorblock_refreshes (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "broadcastItemId" character varying(40) NOT NULL,
    "nextCheckAt" timestamp without time zone NOT NULL,
    "expiresAt" timestamp without time zone NOT NULL,
    "lastCheckedAt" timestamp without time zone,
    "completedAt" timestamp without time zone,
    "lastError" text
);


--
-- Name: broadcast_trigger_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcast_trigger_runs (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "triggerId" character varying(40) NOT NULL,
    reason character varying(255),
    state text DEFAULT 'pending'::text NOT NULL,
    "startedAt" timestamp without time zone,
    "finishedAt" timestamp without time zone,
    "responseSummary" text,
    error text
);


--
-- Name: broadcast_triggers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcast_triggers (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "broadcastId" character varying(40) NOT NULL,
    type text NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    settings text,
    state text DEFAULT 'ready'::text NOT NULL,
    "lastTriggeredAt" timestamp without time zone,
    "lastSuccessAt" timestamp without time zone,
    "lastFailureAt" timestamp without time zone,
    "lastError" text
);


--
-- Name: broadcasts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.broadcasts (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "stashId" character varying(40) NOT NULL,
    type character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    slug character varying(255) NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    "tokenSecretId" character varying(40),
    "tokenPreview" character varying(255),
    settings text,
    "lastPlannedAt" timestamp without time zone,
    "lastBuiltAt" timestamp without time zone,
    "lastVerifiedAt" timestamp without time zone,
    "lastError" text
);


--
-- Name: commands; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.commands (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    type text NOT NULL,
    state text DEFAULT 'accepted'::text NOT NULL,
    "targetType" character varying(255),
    "targetId" character varying(40),
    options text,
    "createdByUserId" character varying(40),
    result text
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "commandId" character varying(40),
    intent text NOT NULL,
    "entityType" character varying(255),
    "entityId" character varying(40),
    state text DEFAULT 'pending'::text NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    "maxAttempts" integer DEFAULT 3 NOT NULL,
    "scheduledAt" timestamp without time zone,
    "startedAt" timestamp without time zone,
    "finishedAt" timestamp without time zone,
    "heartbeatAt" timestamp without time zone,
    "progressCurrent" integer,
    "progressTotal" integer,
    "progressPercent" double precision,
    "progressLabel" character varying(255),
    "progressRate" double precision,
    "progressEtaSeconds" integer,
    "lastError" text,
    payload text,
    "ownerToken" text
);


--
-- Name: login_attempts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.login_attempts (
    "keyHash" character varying(64) NOT NULL,
    attempts integer NOT NULL,
    "expiresAt" timestamp without time zone NOT NULL
);


--
-- Name: media_item_sources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_item_sources (
    id character varying(40) NOT NULL,
    "mediaItemId" character varying(40) NOT NULL,
    "stashInputId" character varying(40),
    "providerKey" character varying(255) NOT NULL,
    "providerInputId" character varying(255) NOT NULL,
    "discoveredUri" text NOT NULL,
    "discoveredAt" timestamp without time zone NOT NULL,
    "position" integer,
    "rawPosition" integer
);


--
-- Name: media_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_items (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "providerKey" character varying(255) NOT NULL,
    "providerItemId" character varying(255) NOT NULL,
    "canonicalUri" text NOT NULL,
    title character varying(255) NOT NULL,
    description text,
    "creatorName" character varying(255),
    "creatorProviderId" character varying(255),
    "durationSeconds" integer,
    "publishedAt" timestamp without time zone,
    "thumbnailUri" text,
    state text DEFAULT 'discovered'::text NOT NULL,
    "metadataCapturedAt" timestamp without time zone,
    "metadataRefreshedAt" timestamp without time zone,
    "lastSeenUpstreamAt" timestamp without time zone,
    "upstreamState" text DEFAULT 'unknown'::text NOT NULL,
    "contentType" text
);


--
-- Name: media_server_connections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_server_connections (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    type text NOT NULL,
    name character varying(255) NOT NULL,
    "baseUri" text NOT NULL,
    "tokenSecretId" character varying(40),
    settings text,
    state text DEFAULT 'ready'::text NOT NULL,
    "lastCheckedAt" timestamp without time zone,
    "lastError" text
);


--
-- Name: media_timeline_entries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.media_timeline_entries (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "mediaItemId" character varying(40) NOT NULL,
    source text NOT NULL,
    kind text NOT NULL,
    category text NOT NULL,
    "startSeconds" double precision NOT NULL,
    "endSeconds" double precision NOT NULL,
    state text DEFAULT 'ready'::text NOT NULL,
    title character varying(255),
    "externalId" character varying(255),
    raw text,
    "lastCheckedAt" timestamp without time zone
);


--
-- Name: secrets; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secrets (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    key character varying(255) NOT NULL,
    type text NOT NULL,
    "encryptedValue" text NOT NULL,
    nonce character varying(64) NOT NULL,
    "tokenDigest" character varying(64),
    metadata text,
    "lastUsedAt" timestamp without time zone,
    "revokedAt" timestamp without time zone
);


--
-- Name: settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.settings (
    key character varying(255) NOT NULL,
    "valueJson" text,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: stash_inputs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stash_inputs (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "stashId" character varying(40) NOT NULL,
    "providerKey" character varying(255) NOT NULL,
    "inputType" text NOT NULL,
    "sourceUri" text NOT NULL,
    "providerInputId" character varying(255) NOT NULL,
    title character varying(255),
    state text DEFAULT 'ready'::text NOT NULL,
    "syncMode" text,
    "lastCheckedAt" timestamp without time zone,
    "nextCheckAt" timestamp without time zone,
    "lastSuccessAt" timestamp without time zone,
    "lastFailureAt" timestamp without time zone,
    "consecutiveFailures" integer DEFAULT 0 NOT NULL,
    options text
);


--
-- Name: stash_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stash_items (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "stashId" character varying(40) NOT NULL,
    "mediaItemId" character varying(40) NOT NULL,
    "stashInputId" character varying(40),
    state text DEFAULT 'active'::text NOT NULL,
    "position" integer,
    "seasonNumber" integer,
    "episodeNumber" integer,
    "seasonTitle" character varying(255),
    "displayTitle" character varying(255),
    "displayDescription" text,
    "firstSeenAt" timestamp without time zone,
    "lastSeenAt" timestamp without time zone,
    "removedAt" timestamp without time zone,
    "removedReason" character varying(255),
    "ignoredReason" character varying(255)
);


--
-- Name: stashes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.stashes (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    "syncMode" text DEFAULT 'automatic'::text NOT NULL,
    "downloadPolicy" text DEFAULT 'video'::text NOT NULL,
    "videoQualityProfileId" character varying(40),
    "audioQualityProfileId" character varying(40),
    "organizationMode" text DEFAULT 'flat'::text NOT NULL,
    state text DEFAULT 'ready'::text NOT NULL,
    "iconUri" text
);


--
-- Name: storage_checks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.storage_checks (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "storageLocationId" character varying(40) NOT NULL,
    "checkType" text NOT NULL,
    state text NOT NULL,
    message text,
    details text
);


--
-- Name: storage_locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.storage_locations (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    key text NOT NULL,
    role text NOT NULL,
    label character varying(255) NOT NULL,
    path text NOT NULL,
    state text DEFAULT 'missing'::text NOT NULL,
    readable boolean DEFAULT false NOT NULL,
    writable boolean DEFAULT false NOT NULL,
    "freeBytes" bigint,
    "totalBytes" bigint,
    "filesystemId" character varying(255),
    "supportsHardlinks" boolean DEFAULT false NOT NULL,
    "supportsSymlinks" boolean DEFAULT false NOT NULL,
    "lastCheckedAt" timestamp without time zone,
    "lastError" text
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id character varying(40) NOT NULL,
    "createdAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "updatedAt" timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    username character varying(255) NOT NULL,
    "passwordHash" character varying(255) NOT NULL,
    role text NOT NULL
);


--
-- Name: activity_events activity_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.activity_events
    ADD CONSTRAINT activity_events_pkey PRIMARY KEY (id);


--
-- Name: api_tokens api_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT api_tokens_pkey PRIMARY KEY (id);


--
-- Name: api_tokens api_tokens_tokenHash_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT "api_tokens_tokenHash_key" UNIQUE ("tokenHash");


--
-- Name: assets assets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT assets_pkey PRIMARY KEY (id);


--
-- Name: broadcast_items broadcast_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_items
    ADD CONSTRAINT broadcast_items_pkey PRIMARY KEY (id);


--
-- Name: broadcast_sponsorblock_refreshes broadcast_sponsorblock_refreshes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_sponsorblock_refreshes
    ADD CONSTRAINT broadcast_sponsorblock_refreshes_pkey PRIMARY KEY (id);


--
-- Name: broadcast_trigger_runs broadcast_trigger_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_trigger_runs
    ADD CONSTRAINT broadcast_trigger_runs_pkey PRIMARY KEY (id);


--
-- Name: broadcast_triggers broadcast_triggers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_triggers
    ADD CONSTRAINT broadcast_triggers_pkey PRIMARY KEY (id);


--
-- Name: broadcasts broadcasts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT broadcasts_pkey PRIMARY KEY (id);


--
-- Name: commands commands_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.commands
    ADD CONSTRAINT commands_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: login_attempts login_attempts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.login_attempts
    ADD CONSTRAINT login_attempts_pkey PRIMARY KEY ("keyHash");


--
-- Name: media_item_sources media_item_sources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_item_sources
    ADD CONSTRAINT media_item_sources_pkey PRIMARY KEY (id);


--
-- Name: media_items media_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_items
    ADD CONSTRAINT media_items_pkey PRIMARY KEY (id);


--
-- Name: media_server_connections media_server_connections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_server_connections
    ADD CONSTRAINT media_server_connections_pkey PRIMARY KEY (id);


--
-- Name: media_timeline_entries media_timeline_entries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_timeline_entries
    ADD CONSTRAINT media_timeline_entries_pkey PRIMARY KEY (id);


--
-- Name: secrets secrets_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secrets
    ADD CONSTRAINT secrets_key_key UNIQUE (key);


--
-- Name: secrets secrets_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secrets
    ADD CONSTRAINT secrets_pkey PRIMARY KEY (id);


--
-- Name: secrets secrets_tokenDigest_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secrets
    ADD CONSTRAINT "secrets_tokenDigest_key" UNIQUE ("tokenDigest");


--
-- Name: settings settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (key);


--
-- Name: stash_inputs stash_inputs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_inputs
    ADD CONSTRAINT stash_inputs_pkey PRIMARY KEY (id);


--
-- Name: stash_items stash_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_items
    ADD CONSTRAINT stash_items_pkey PRIMARY KEY (id);


--
-- Name: stashes stashes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stashes
    ADD CONSTRAINT stashes_pkey PRIMARY KEY (id);


--
-- Name: storage_checks storage_checks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_checks
    ADD CONSTRAINT storage_checks_pkey PRIMARY KEY (id);


--
-- Name: storage_locations storage_locations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_locations
    ADD CONSTRAINT storage_locations_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: activity_events_command_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_events_command_id ON public.activity_events USING btree ("commandId");


--
-- Name: activity_events_created_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_events_created_at ON public.activity_events USING btree ("createdAt");


--
-- Name: activity_events_job_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX activity_events_job_id ON public.activity_events USING btree ("jobId");


--
-- Name: api_tokens_user_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX api_tokens_user_id ON public.api_tokens USING btree ("userId");


--
-- Name: assets_broadcast_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assets_broadcast_id ON public.assets USING btree ("broadcastId");


--
-- Name: assets_broadcast_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assets_broadcast_item_id ON public.assets USING btree ("broadcastItemId");


--
-- Name: assets_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assets_media_item_id ON public.assets USING btree ("mediaItemId");


--
-- Name: assets_role; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assets_role ON public.assets USING btree (role);


--
-- Name: assets_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX assets_state ON public.assets USING btree (state);


--
-- Name: broadcast_items_broadcast_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_items_broadcast_id ON public.broadcast_items USING btree ("broadcastId");


--
-- Name: broadcast_items_broadcast_id_stash_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX broadcast_items_broadcast_id_stash_item_id ON public.broadcast_items USING btree ("broadcastId", "stashItemId");


--
-- Name: broadcast_items_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_items_media_item_id ON public.broadcast_items USING btree ("mediaItemId");


--
-- Name: broadcast_items_stash_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_items_stash_item_id ON public.broadcast_items USING btree ("stashItemId");


--
-- Name: broadcast_sponsorblock_refreshes_broadcast_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX broadcast_sponsorblock_refreshes_broadcast_item_id ON public.broadcast_sponsorblock_refreshes USING btree ("broadcastItemId");


--
-- Name: broadcast_sponsorblock_refreshes_completed_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_sponsorblock_refreshes_completed_at ON public.broadcast_sponsorblock_refreshes USING btree ("completedAt");


--
-- Name: broadcast_sponsorblock_refreshes_next_check_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_sponsorblock_refreshes_next_check_at ON public.broadcast_sponsorblock_refreshes USING btree ("nextCheckAt");


--
-- Name: broadcast_trigger_runs_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_trigger_runs_state ON public.broadcast_trigger_runs USING btree (state);


--
-- Name: broadcast_trigger_runs_trigger_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_trigger_runs_trigger_id ON public.broadcast_trigger_runs USING btree ("triggerId");


--
-- Name: broadcast_triggers_broadcast_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_triggers_broadcast_id ON public.broadcast_triggers USING btree ("broadcastId");


--
-- Name: broadcast_triggers_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcast_triggers_state ON public.broadcast_triggers USING btree (state);


--
-- Name: broadcasts_stash_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcasts_stash_id ON public.broadcasts USING btree ("stashId");


--
-- Name: broadcasts_stash_id_slug; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX broadcasts_stash_id_slug ON public.broadcasts USING btree ("stashId", slug);


--
-- Name: broadcasts_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX broadcasts_state ON public.broadcasts USING btree (state);


--
-- Name: jobs_media_item_download_history; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_media_item_download_history ON public.jobs USING btree ("entityType", intent, "entityId", "createdAt" DESC, id DESC);


--
-- Name: jobs_pending_claim; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_pending_claim ON public.jobs USING btree (state, priority, "createdAt");


--
-- Name: jobs_processing_heartbeat; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_processing_heartbeat ON public.jobs USING btree (state, "heartbeatAt");


--
-- Name: login_attempts_expires_at; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX login_attempts_expires_at ON public.login_attempts USING btree ("expiresAt");


--
-- Name: media_item_sources_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_item_sources_media_item_id ON public.media_item_sources USING btree ("mediaItemId");


--
-- Name: media_item_sources_stash_input_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_item_sources_stash_input_id ON public.media_item_sources USING btree ("stashInputId");


--
-- Name: media_items_provider_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_items_provider_key ON public.media_items USING btree ("providerKey");


--
-- Name: media_items_provider_key_provider_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX media_items_provider_key_provider_item_id ON public.media_items USING btree ("providerKey", "providerItemId");


--
-- Name: media_items_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_items_state ON public.media_items USING btree (state);


--
-- Name: media_server_connections_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_server_connections_state ON public.media_server_connections USING btree (state);


--
-- Name: media_server_connections_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_server_connections_type ON public.media_server_connections USING btree (type);


--
-- Name: media_timeline_entries_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_timeline_entries_media_item_id ON public.media_timeline_entries USING btree ("mediaItemId");


--
-- Name: media_timeline_entries_media_item_id_source_external_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX media_timeline_entries_media_item_id_source_external_id ON public.media_timeline_entries USING btree ("mediaItemId", source, "externalId");


--
-- Name: media_timeline_entries_source; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX media_timeline_entries_source ON public.media_timeline_entries USING btree (source);


--
-- Name: stash_inputs_provider_key; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stash_inputs_provider_key ON public.stash_inputs USING btree ("providerKey");


--
-- Name: stash_inputs_stash_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stash_inputs_stash_id ON public.stash_inputs USING btree ("stashId");


--
-- Name: stash_inputs_stash_id_provider_key_provider_input_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX stash_inputs_stash_id_provider_key_provider_input_id ON public.stash_inputs USING btree ("stashId", "providerKey", "providerInputId");


--
-- Name: stash_items_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stash_items_media_item_id ON public.stash_items USING btree ("mediaItemId");


--
-- Name: stash_items_stash_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stash_items_stash_id ON public.stash_items USING btree ("stashId");


--
-- Name: stash_items_stash_id_media_item_id; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX stash_items_stash_id_media_item_id ON public.stash_items USING btree ("stashId", "mediaItemId");


--
-- Name: stash_items_stash_input_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stash_items_stash_input_id ON public.stash_items USING btree ("stashInputId");


--
-- Name: stashes_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX stashes_state ON public.stashes USING btree (state);


--
-- Name: users_single_owner; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_single_owner ON public.users USING btree ((1));


--
-- Name: users_username; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_username ON public.users USING btree (username);


--
-- Name: api_tokens api_tokens_userId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.api_tokens
    ADD CONSTRAINT "api_tokens_userId_fkey" FOREIGN KEY ("userId") REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: assets assets_broadcastId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT "assets_broadcastId_fkey" FOREIGN KEY ("broadcastId") REFERENCES public.broadcasts(id) ON DELETE CASCADE;


--
-- Name: assets assets_broadcastItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT "assets_broadcastItemId_fkey" FOREIGN KEY ("broadcastItemId") REFERENCES public.broadcast_items(id) ON DELETE CASCADE;


--
-- Name: assets assets_derivedFromAssetId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT "assets_derivedFromAssetId_fkey" FOREIGN KEY ("derivedFromAssetId") REFERENCES public.assets(id) ON DELETE SET NULL;


--
-- Name: assets assets_mediaItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.assets
    ADD CONSTRAINT "assets_mediaItemId_fkey" FOREIGN KEY ("mediaItemId") REFERENCES public.media_items(id) ON DELETE CASCADE;


--
-- Name: broadcast_items broadcast_items_broadcastId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_items
    ADD CONSTRAINT "broadcast_items_broadcastId_fkey" FOREIGN KEY ("broadcastId") REFERENCES public.broadcasts(id) ON DELETE CASCADE;


--
-- Name: broadcast_items broadcast_items_mediaItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_items
    ADD CONSTRAINT "broadcast_items_mediaItemId_fkey" FOREIGN KEY ("mediaItemId") REFERENCES public.media_items(id) ON DELETE CASCADE;


--
-- Name: broadcast_items broadcast_items_stashItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_items
    ADD CONSTRAINT "broadcast_items_stashItemId_fkey" FOREIGN KEY ("stashItemId") REFERENCES public.stash_items(id) ON DELETE CASCADE;


--
-- Name: broadcast_items broadcast_items_tokenSecretId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_items
    ADD CONSTRAINT "broadcast_items_tokenSecretId_fkey" FOREIGN KEY ("tokenSecretId") REFERENCES public.secrets(id) ON DELETE SET NULL;


--
-- Name: broadcast_sponsorblock_refreshes broadcast_sponsorblock_refreshes_broadcastItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_sponsorblock_refreshes
    ADD CONSTRAINT "broadcast_sponsorblock_refreshes_broadcastItemId_fkey" FOREIGN KEY ("broadcastItemId") REFERENCES public.broadcast_items(id) ON DELETE CASCADE;


--
-- Name: broadcast_trigger_runs broadcast_trigger_runs_triggerId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_trigger_runs
    ADD CONSTRAINT "broadcast_trigger_runs_triggerId_fkey" FOREIGN KEY ("triggerId") REFERENCES public.broadcast_triggers(id) ON DELETE CASCADE;


--
-- Name: broadcast_triggers broadcast_triggers_broadcastId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcast_triggers
    ADD CONSTRAINT "broadcast_triggers_broadcastId_fkey" FOREIGN KEY ("broadcastId") REFERENCES public.broadcasts(id) ON DELETE CASCADE;


--
-- Name: broadcasts broadcasts_stashId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT "broadcasts_stashId_fkey" FOREIGN KEY ("stashId") REFERENCES public.stashes(id) ON DELETE CASCADE;


--
-- Name: broadcasts broadcasts_tokenSecretId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.broadcasts
    ADD CONSTRAINT "broadcasts_tokenSecretId_fkey" FOREIGN KEY ("tokenSecretId") REFERENCES public.secrets(id) ON DELETE SET NULL;


--
-- Name: jobs jobs_commandId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT "jobs_commandId_fkey" FOREIGN KEY ("commandId") REFERENCES public.commands(id) ON DELETE SET NULL;


--
-- Name: media_item_sources media_item_sources_mediaItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_item_sources
    ADD CONSTRAINT "media_item_sources_mediaItemId_fkey" FOREIGN KEY ("mediaItemId") REFERENCES public.media_items(id) ON DELETE CASCADE;


--
-- Name: media_item_sources media_item_sources_stashInputId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_item_sources
    ADD CONSTRAINT "media_item_sources_stashInputId_fkey" FOREIGN KEY ("stashInputId") REFERENCES public.stash_inputs(id) ON DELETE SET NULL;


--
-- Name: media_server_connections media_server_connections_tokenSecretId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_server_connections
    ADD CONSTRAINT "media_server_connections_tokenSecretId_fkey" FOREIGN KEY ("tokenSecretId") REFERENCES public.secrets(id) ON DELETE SET NULL;


--
-- Name: media_timeline_entries media_timeline_entries_mediaItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.media_timeline_entries
    ADD CONSTRAINT "media_timeline_entries_mediaItemId_fkey" FOREIGN KEY ("mediaItemId") REFERENCES public.media_items(id) ON DELETE CASCADE;


--
-- Name: stash_inputs stash_inputs_stashId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_inputs
    ADD CONSTRAINT "stash_inputs_stashId_fkey" FOREIGN KEY ("stashId") REFERENCES public.stashes(id) ON DELETE CASCADE;


--
-- Name: stash_items stash_items_mediaItemId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_items
    ADD CONSTRAINT "stash_items_mediaItemId_fkey" FOREIGN KEY ("mediaItemId") REFERENCES public.media_items(id) ON DELETE CASCADE;


--
-- Name: stash_items stash_items_stashId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_items
    ADD CONSTRAINT "stash_items_stashId_fkey" FOREIGN KEY ("stashId") REFERENCES public.stashes(id) ON DELETE CASCADE;


--
-- Name: stash_items stash_items_stashInputId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.stash_items
    ADD CONSTRAINT "stash_items_stashInputId_fkey" FOREIGN KEY ("stashInputId") REFERENCES public.stash_inputs(id) ON DELETE SET NULL;


--
-- Name: storage_checks storage_checks_storageLocationId_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.storage_checks
    ADD CONSTRAINT "storage_checks_storageLocationId_fkey" FOREIGN KEY ("storageLocationId") REFERENCES public.storage_locations(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
