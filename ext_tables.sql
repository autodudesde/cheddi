#
# Table structure for table 'tx_cheddi_session'
#
CREATE TABLE tx_cheddi_session (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,
    deleted tinyint(4) DEFAULT '0' NOT NULL,

    session_uuid varchar(36) DEFAULT '' NOT NULL,
    be_user int(11) DEFAULT '0' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    model varchar(64) DEFAULT '' NOT NULL,
    last_activity int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY session_uuid (session_uuid),
    KEY be_user_activity (be_user, last_activity)
);

#
# Table structure for table 'tx_cheddi_message'
#
CREATE TABLE tx_cheddi_message (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    session int(11) DEFAULT '0' NOT NULL,
    sort int(11) DEFAULT '0' NOT NULL,
    role varchar(16) DEFAULT '' NOT NULL,
    content mediumtext,
    tool_calls mediumtext,
    tool_call_id varchar(64) DEFAULT '' NOT NULL,
    tool_status varchar(16) DEFAULT '' NOT NULL,
    # File references of a user message, as JSON. Never the file bytes.
    attachments mediumtext,
    # Provider-native items of an assistant turn, as JSON. Opaque: stored and replayed unread.
    provider_items mediumtext,
    # Web research sources of a tool message, as JSON. Read back so the closing turn, which runs in a later request, can still carry them.
    sources mediumtext,

    PRIMARY KEY (uid),
    KEY session_sort (session, sort)
);

#
# Table structure for table 'tx_cheddi_change'
#
# Audit trail of the workspace mutations a chat session caused. Only written when
# the session writes into a draft workspace; in live mode there is nothing to review.
#
CREATE TABLE tx_cheddi_change (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) DEFAULT '0' NOT NULL,
    crdate int(11) DEFAULT '0' NOT NULL,

    session int(11) DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) DEFAULT '0' NOT NULL,
    workspace_record_uid int(11) DEFAULT '0' NOT NULL,
    workspace int(11) DEFAULT '0' NOT NULL,
    page_id int(11) DEFAULT '0' NOT NULL,
    action varchar(16) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY session (session),
    KEY record (tablename, record_uid)
);
