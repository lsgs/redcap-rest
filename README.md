# REDCap REST

## Description

An external module enabling REDCap to send outbound API calls when saving data entry or survey forms and specified conditions are met. This can facilitate copying of data from your REDCap project to another application via its API, or to another REDCap project in either the same or a different instance of REDCap.

## Limitations

* It is **strongly recommended** that this module be set to require module-specific privileges because user API tokens may be required to be entered into configuration settings.
* Initial implementation is of outbound API calls only.
* Initial implementation does not facilitate authentication mecahnisms like OAuth2.

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
```json
{
  "consent": [consent],
  "consent_date": "[consentdt]"
}
```

**Content Type**
* *Optional* Option to specify an alternative to 'application/json'.

**Additional Headers**
* *Optional* Additional headers along with Content-Type and Content-Length. Piping supported.

**cURL Options**
* *Optional* Key-value pairs for cURL settings, one pair per line in the notes box. Piping supported.

**Capture of Return Data**

API response data can be captured into fields within the same event as the triggering form.

**Result Field**
* *Optional* Select a field (e.g. a Notes-type field) in which to store the entire response (useful for debugging or for extracting values from complex reposnses using JavaScript).

**Map JSON Response Data to Fields**
* *Optional* *Repeating* For JSON reponses, enter a property value to find in the response and a corresponding field name into which the property's value will be stored.

## Examples
### REDCap API
Call a REDCap API endpoint to obtain the value of field `[fieldtogetvaluefor]` for the record id piped in from field `[recordtofind]`:
* Destination URL: `https://redcap.someplace.edu/api/`
* HTTP Method: `POST`
* Payload form: `token=FEDCBA98765432100123456789ABCDEF&content=record&type=flat&format=json&records=[recordtofind]&fields[]=record_id&fields[]=fieldtogetvaluefor`
* Content Type: `application/x-www-form-urlencoded` (note *not* `application/json`)

### Australia/New Zealand Clinical Trial Registry (https://anzctr.org.au/)
Obtain published details of a clinical trial identified using its ANZCTR ID (piped into paylod using `[anzctrid]`): 
* Destination URL: `https://www.anzctr.org.au/WebServices/AnzctrWebservices.asmx`
* HTTP Method: `POST`
* Payload form: 
```xml
<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <AnzctrTrialDetails xmlns="http://anzctr.org.au/WebServices/AnzctrWebServices">
      <ids>[anzctrid]</ids>
    </AnzctrTrialDetails>
  </soap12:Body>
</soap12:Envelope>
```
* Content Type: `application/soap+xml; charset=utf-8`

### Basic Authentication
Send a payload to an API endpoint secured with Basic Auth, uncluding an encoded token as an HTTP header:
* Destination URL: `https://deep.thought.org/endpoint/`
* HTTP Method: `POST`
* Payload form: 
```json
{ "answer":42 }
```
* Content Type: ``
* Additional headers: `Authorization: Basic SWYgdGhhdCdzIHRoZSBhbnN3ZXIsIHdoYXQgaXMgdGhlIHF1ZXN0aW9uPw==`
