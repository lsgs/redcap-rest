# REDCap REST

## Description

An external module enabling REDCap to send (and potentially in future receive) API calls.

## Limitations

* Initial implementation is of outbound calls only.

## Configuration

Multiple outbound API messages can be configured via the External Modules Configure dialog.

**Enabled?**
* Check to enable a message.

**Trigger form(s)**
* One or more instruments for which the current message will be triggered.

**Trigger condition**
* *Optional*: REDCap logic expression that must evaluate to *true* for the current record in order for the message to be generated. Leave empty to always send on saving the trigger form(s).
	
**Destination URL**
* The URL of the endpoint the message will be sent *to*. Piping supported.
```
https://consentmgt.ourplace.org/api/record/[record_id]
```

**Payload**
* *Optional*: Textarea for specifying the form of the payload in JSON format. Piping supported.
```
{
  "consent": [consent],
  "consent_date": "[consentdt]"
}
```

**cURL Options**
* *Optional* *Repeatable*: Key-value pairs for cURL settings. Piping supported.